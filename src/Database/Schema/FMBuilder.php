<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Schema;

use Illuminate\Database\Schema\Builder;

class FMBuilder extends Builder
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
        $fieldMetaData = $layoutMetaData['response']['fieldMetaData'];
        $columns = array_column($fieldMetaData, 'name');

        return $columns;
    }
}
