<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Query;

use DateTimeInterface;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerODataException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RuntimeException;

class FMBaseBuilder extends Builder
{
    /**
     * An array of fields to map to FileMaker fields
     *
     * @var array
     */
    protected $fieldMapping = [];

    /**
     * Field data to be used when creating or editing a record
     *
     * @var array
     */
    public $fieldData;

    public const ASCEND = 'asc';

    public const DESCEND = 'desc';

    /**
     * All of the available clause operators.
     *
     * @var string[]
     */
    public $operators = [
        '=', '==', '≠', '!=', '<>', '<', '>', '<=', '≤', '>=', '≥', 'like', 'not like',
    ];

    /**
     * Set the "orders" for the query, normalizing FileMaker's "ascend"/"descend" direction
     * vocabulary to the standard "asc"/"desc" that the base Builder (and our grammar) expect.
     */
    public function orderBy($column, $direction = self::ASCEND): FMBaseBuilder
    {
        // $direction may be a SortDirection enum rather than a string - Laravel passes one
        // internally from enforceOrderBy() (used by chunk(), each(), lazy(), ...). Leave
        // anything that isn't a string alone and let the parent handle it.
        if (is_string($direction)) {
            $direction = match (strtolower($direction)) {
                'ascend' => 'asc',
                'descend' => 'desc',
                default => $direction,
            };
        }

        parent::orderBy($column, $direction);

        return $this;
    }

    /**
     * Alias for orderBy
     *
     * @return $this
     */
    public function sort($column, string $direction = self::ASCEND): FMBaseBuilder
    {
        return $this->orderBy($column, $direction);
    }

    /**
     * Convenience method for sorting in descending order
     */
    public function orderByDesc($column): FMBaseBuilder
    {
        return $this->orderBy($column, self::DESCEND);
    }

    /**
     * @return Collection
     *
     * @throws FileMakerODataException
     */
    public function get($columns = ['*'])
    {
        $records = collect($this->connection->getRecords($this));

        if ($columns !== ['*']) {
            $records = $records->map(fn ($record) => Arr::only($record, $columns));
        }

        return $records;
    }

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $response = $this->forPage($page, $perPage)->connection->selectWithCount($this);

        $results = collect($response['value'] ?? []);

        return $this->paginator($results, $response['@odata.count'] ?? 0, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Gets the name of the mapped FileMaker field for a particular column. Public because
     * FMGrammar needs it to translate where/order columns when compiling the $filter/$orderby.
     *
     * @return string
     */
    public function getMappedFieldName(string $column)
    {
        return array_flip($this->getFieldMapping())[$column] ?? $column;
    }

    /**
     * A helper function to map an entire array of fields and data to their FileMaker field names
     *
     * @param  $array  array An array of columns and their values
     */
    protected function mapFieldNamesForArray(array $array): array
    {
        $mappedArray = [];
        foreach ($array as $column => $value) {
            $mappedArray[$this->getMappedFieldName($column)] = $value;
        }

        return $mappedArray;
    }

    public function getFieldMapping(): array
    {
        return $this->fieldMapping;
    }

    /**
     * @param  array  $fieldMapping
     */
    public function setFieldMapping($fieldMapping): void
    {
        $this->fieldMapping = $fieldMapping;
    }

    /**
     * OData has no subquery support. Laravel folds a queryable into a single Expression
     * value rather than a distinct where "type", so the grammar can't tell it apart from a
     * legitimate expression - it has to be caught here, or it compiles to `column in ('')`.
     *
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($this->isQueryable($values)) {
            throw new RuntimeException('Subqueries are not supported by the OData query grammar.');
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Create a new query instance for a nested where group, carrying over the field
     * mapping so columns referenced inside the closure still map to FileMaker field names.
     */
    public function forNestedWhere()
    {
        $query = parent::forNestedWhere();

        $query->setFieldMapping($this->getFieldMapping());

        return $query;
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return mixed
     */
    public function min($column, $direction = self::ASCEND)
    {
        $result = $this->orderBy($column, $direction)->first();

        return $result[$this->getMappedFieldName($column)] ?? null;
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->min($column, self::DESCEND);
    }

    /**
     * Edit the record and get the raw OData response
     *
     * @throws FileMakerODataException
     */
    public function editRecord()
    {
        return $this->connection->update($this);
    }

    /**
     * Create a record and get the raw OData response
     *
     * @throws FileMakerODataException
     */
    public function createRecord()
    {
        return $this->connection->createRecord($this);
    }

    /**
     * Set the field data to be used when creating or editing a record
     *
     * @param  $array  array
     * @return $this
     */
    public function fieldData(array $array)
    {
        $this->fieldData = $this->mapFieldNamesForArray($array);

        return $this;
    }

    /**
     * Insert new records into the database.
     *
     * @return array
     */
    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }

        $this->fieldData = $this->mapFieldNamesForArray($values);

        return $this->connection->createRecord($this);
    }

    /**
     * Update records in the database.
     *
     * @return int
     */
    public function update(array $values)
    {
        $this->applyBeforeQueryCallbacks();

        $this->fieldData($values);

        return $this->connection->update($this);
    }

    /**
     * Delete records from the database.
     *
     * @throws FileMakerODataException
     */
    public function delete($id = null): int
    {
        if (! is_null($id)) {
            $this->where($this->defaultKeyName(), '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->delete($this);
    }

    /**
     * Retrieve the "count" result of the query using OData's $count path segment.
     *
     * @param  string  $columns
     * @return int
     */
    public function count($columns = '*')
    {
        return $this->connection->count($this);
    }

    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        if (is_null($value)) {
            $value = $operator;
            $operator = '=';
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        // A date field is compared against a bare OData date literal (2020-01-31). Passing
        // it as an expression keeps the grammar from quoting it into an Edm.String, which
        // the server would reject as a type mismatch.
        return $this->where($column, $operator, new Expression($value), $boolean);
    }

    public function toSql(): string
    {
        return $this->getGrammar()->compileWheres($this);
    }

    public function toRawSql(): string
    {
        return $this->toSql();
    }

    protected function defaultKeyName()
    {
        return 'id';
    }

    protected function isContainer($field)
    {
        if (is_a($field, File::class)) {
            return true;
        }

        if (is_array($field) && count($field) === 2 && $this->isFile($field[0])) {
            return true;
        }

        return false;
    }

    protected function isFile($object)
    {
        return is_a($object, File::class) ||
            is_a($object, UploadedFile::class);
    }
}
