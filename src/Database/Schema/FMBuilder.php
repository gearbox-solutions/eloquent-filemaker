<?php

namespace BlueFeather\EloquentFileMaker\Database\Schema;

class FMBuilder extends \Illuminate\Database\Schema\Builder
{
    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumnListing($table)
    {
        $layoutMetaData = $this->connection->getLayoutMetadata($table);
        $fieldMetaData = $layoutMetaData['fieldMetaData'];
        $columns = array_column($fieldMetaData, 'name');

        return $columns;
    }

}
