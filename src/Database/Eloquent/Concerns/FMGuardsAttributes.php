<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns;

use Illuminate\Support\Facades\Cache;

trait FMGuardsAttributes
{
    /**
     * Determine if the given key is guarded.
     *
     * @param  string  $key
     * @return bool
     */
    public function isGuarded($key)
    {
        if (empty($this->getGuarded())) {
            return false;
        }

        return $this->getGuarded() == ['*'] ||
            ! empty(preg_grep('/^' . preg_quote($key) . '$/i', $this->getGuarded())) ||
            ! $this->isGuardableColumn($key);
    }

    /**
     * Determine if the given column is a valid, guardable column.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isGuardableColumn($key)
    {
        $this->primeGuardableColumns();

        if (in_array($key, static::$guardableColumns[get_class($this)])) {
            return true;
        }

        $this->primeGuardableColumns(true);

        return in_array($key, static::$guardableColumns[get_class($this)]);
    }

    protected function primeGuardableColumns($forceRefresh = false)
    {
        if (! isset(static::$guardableColumns[get_class($this)])) {
            $columns = $this->getColumns($forceRefresh);

            if (empty($columns)) {
                return true;
            }
            static::$guardableColumns[get_class($this)] = $columns;
        }
    }

    protected function getColumns($forceRefresh = false): array
    {
        $cacheKey = "eloquent-filemaker-{$this->table}-columns";
        $refreshCallback = function () {
            $layoutMetaData = $this->getConnection()->getLayoutMetadata($this->table);

            return array_column($layoutMetaData['fieldMetaData'], 'name');
        };

        if ($forceRefresh) {
            Cache::forever($cacheKey, $columns = $refreshCallback());

            return $columns;
        }

        return Cache::rememberForever($cacheKey, $refreshCallback);
    }
}
