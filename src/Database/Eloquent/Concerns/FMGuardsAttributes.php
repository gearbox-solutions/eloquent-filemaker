<?php

namespace BlueFeather\EloquentFileMaker\Database\Eloquent\Concerns;

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
            ! empty(preg_grep('/^'.preg_quote($key).'$/i', $this->getGuarded())) ||
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
        if (! isset(static::$guardableColumns[get_class($this)])) {
            $columns = $this->getColumns();

            if (empty($columns)) {
                return true;
            }
            static::$guardableColumns[get_class($this)] = $columns;
        }

        return in_array($key, static::$guardableColumns[get_class($this)]);
    }



    protected function getColumns(): array{
        $layoutMetaData = $this->getConnection()->getLayoutMetadata($this->table);
        $fieldMetaData = $layoutMetaData['fieldMetaData'];
        $columns = array_column($fieldMetaData, 'name');

        return $columns;
    }

}
