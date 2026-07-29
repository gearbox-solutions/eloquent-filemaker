<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Query\Grammars;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Compiles a query builder's where/order state into an OData $filter / $orderby expression
 * instead of SQL. Leaf where-compilers are dispatched the same way the base Grammar does
 * (dynamically, by where "type"), so standard Builder methods (where, orWhere, whereIn,
 * whereNull, whereBetween, whereNested, ...) can be used unmodified.
 */
class FMGrammar extends Grammar
{
    /**
     * Map of Laravel/FileMaker comparison operators to their OData equivalents.
     */
    protected array $operatorMap = [
        '=' => 'eq', '==' => 'eq',
        '!=' => 'ne', '<>' => 'ne', '≠' => 'ne',
        '<' => 'lt',
        '>' => 'gt',
        '<=' => 'le', '≤' => 'le',
        '>=' => 'ge', '≥' => 'ge',
    ];

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return DateTimeInterface::ATOM;
    }

    public function substituteBindingsIntoRawSql($sql, $bindings)
    {
        return $sql;
    }

    /**
     * Compile the query's wheres into a bare OData $filter expression (no "where " prefix).
     */
    public function compileWheres(Builder $query)
    {
        if (is_null($query->wheres)) {
            return '';
        }

        return $this->filterFromWheres($query);
    }

    /**
     * Build the $filter expression for a query's where clauses, without any leading
     * boolean connector. Used for both the top-level query and nested where groups.
     */
    protected function filterFromWheres(Builder $query)
    {
        if (empty($query->wheres)) {
            return '';
        }

        $fragments = [];

        foreach ($query->wheres as $where) {
            [$boolean, $negate] = $this->parseWhereBoolean($where['boolean']);

            $method = 'where' . $where['type'];

            if (! method_exists($this, $method)) {
                throw new RuntimeException("The [{$where['type']}] where clause type is not supported by the OData query grammar.");
            }

            $fragment = $this->{$method}($query, $where);

            if ($fragment === '') {
                continue;
            }

            if ($negate) {
                $fragment = "not ({$fragment})";
            }

            $fragments[] = empty($fragments) ? $fragment : $boolean . ' ' . $fragment;
        }

        return implode(' ', $fragments);
    }

    /**
     * Split a Laravel where "boolean" (e.g. "and", "or", "and not", "or not") into its
     * connector ("and"/"or") and whether the clause should be negated.
     */
    protected function parseWhereBoolean($boolean)
    {
        $boolean = trim($boolean);

        if (str_ends_with($boolean, ' not')) {
            return [trim(substr($boolean, 0, -4)), true];
        }

        return [$boolean, false];
    }

    /**
     * Wrap a where/order "column", first translating it from the model attribute name to its
     * mapped FileMaker field name (see FMBaseBuilder::getMappedFieldName()). The leaf where
     * compilers no longer go through a custom where() override, so this is the one place left
     * where field-name mapping needs to be applied.
     */
    protected function wrapMapped(Builder $query, $column)
    {
        return $this->wrap($query->getMappedFieldName($column));
    }

    /**
     * Wrap a single field name for use in a $filter/$orderby expression. FileMaker's OData
     * implementation only requires (and accepts) double-quoting for field names containing
     * characters other than letters and digits.
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        if (preg_match('/[^A-Za-z0-9]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * Get the appropriate OData literal for a value. Unlike stock Grammar (which returns a
     * "?" placeholder for PDO binding), this inlines the literal directly since the compiled
     * expression is sent as-is in the request URL/body, not executed through PDO.
     */
    public function parameter($value)
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        return $this->quoteODataValue($value);
    }

    protected function quoteODataValue($value)
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format($this->getDateFormat());
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    protected function whereBasic(Builder $query, $where)
    {
        $operator = strtolower($where['operator']);

        if ($operator === 'like' || $operator === 'not like') {
            $fragment = $this->compileLikeFragment($this->wrapMapped($query, $where['column']), $where['value']);

            return $operator === 'not like' ? "not ({$fragment})" : $fragment;
        }

        $odataOperator = $this->operatorMap[$where['operator']] ?? null;

        if ($odataOperator === null) {
            throw new InvalidArgumentException("The [{$where['operator']}] operator is not supported by the OData query grammar.");
        }

        return $this->wrapMapped($query, $where['column']) . ' ' . $odataOperator . ' ' . $this->parameter($where['value']);
    }

    protected function whereLike(Builder $query, $where)
    {
        if (! empty($where['caseSensitive'])) {
            throw new RuntimeException('Case sensitive LIKE clauses are not supported by the OData query grammar.');
        }

        $fragment = $this->compileLikeFragment($this->wrapMapped($query, $where['column']), $where['value']);

        return ($where['not'] ?? false) ? "not ({$fragment})" : $fragment;
    }

    /**
     * Translate a SQL LIKE pattern (using % wildcards) into the closest OData string function.
     */
    protected function compileLikeFragment($wrappedColumn, $value)
    {
        $startsWithWildcard = str_starts_with($value, '%');
        $endsWithWildcard = str_ends_with($value, '%');
        $inner = trim($value, '%');

        if (str_contains($inner, '%')) {
            throw new InvalidArgumentException('LIKE patterns with wildcards in the middle of the value are not supported by the OData query grammar.');
        }

        $literal = $this->parameter($inner);

        if ($startsWithWildcard && $endsWithWildcard) {
            return "contains({$wrappedColumn}, {$literal})";
        }

        if ($endsWithWildcard) {
            return "startswith({$wrappedColumn}, {$literal})";
        }

        if ($startsWithWildcard) {
            return "endswith({$wrappedColumn}, {$literal})";
        }

        return "{$wrappedColumn} eq {$literal}";
    }

    protected function whereIn(Builder $query, $where)
    {
        if (empty($where['values'])) {
            return 'false';
        }

        $values = (new Collection($where['values']))->map(fn ($value) => $this->parameter($value))->implode(',');

        return $this->wrapMapped($query, $where['column']) . ' in (' . $values . ')';
    }

    protected function whereNotIn(Builder $query, $where)
    {
        if (empty($where['values'])) {
            return 'true';
        }

        return 'not (' . $this->whereIn($query, $where) . ')';
    }

    /**
     * FileMaker has no true null - empty/unset values round-trip as either JSON null or an
     * empty string, so treat both as equivalent to "null" for query purposes.
     */
    protected function whereNull(Builder $query, $where)
    {
        $column = $this->wrapMapped($query, $where['column']);

        return "({$column} eq null or {$column} eq '')";
    }

    protected function whereNotNull(Builder $query, $where)
    {
        $column = $this->wrapMapped($query, $where['column']);

        return "({$column} ne null and {$column} ne '')";
    }

    protected function whereBetween(Builder $query, $where)
    {
        $column = $this->wrapMapped($query, $where['column']);

        $min = $this->parameter(reset($where['values']));
        $max = $this->parameter(end($where['values']));

        $condition = "{$column} ge {$min} and {$column} le {$max}";

        return ($where['not'] ?? false) ? "not ({$condition})" : "({$condition})";
    }

    protected function whereNested(Builder $query, $where)
    {
        $filter = $this->filterFromWheres($where['query']);

        return $filter === '' ? '' : '(' . $filter . ')';
    }

    protected function whereRaw(Builder $query, $where)
    {
        throw new RuntimeException('Raw where clauses are not supported by the OData query grammar.');
    }

    /**
     * Compile the query's orders into a bare $orderby expression (no "order by " prefix).
     */
    public function compileOrders(Builder $query, $orders)
    {
        if (empty($orders)) {
            return '';
        }

        return (new Collection($orders))->map(function ($order) use ($query) {
            $direction = strtolower($order['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

            return $this->wrapMapped($query, $order['column']) . ' ' . $direction;
        })->implode(',');
    }
}
