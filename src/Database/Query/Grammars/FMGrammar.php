<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\Grammar;

class FMGrammar extends Grammar
{

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'n/j/Y g:i:s A';
    }

}
