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
        return $this->connection->getTableMetadata($table);
    }
}
