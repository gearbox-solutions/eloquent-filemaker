<?php

namespace Tests\Models;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;

/**
 * A model with a specific guarded field list. Guarding specific fields makes
 * the package fetch the table metadata to validate guardable columns, so this
 * model is kept separate from the general-purpose Person test model.
 */
class GuardedPerson extends FMModel
{
    protected $connection = 'filemaker';

    protected $table = 'person';

    protected $primaryKey = 'primaryKey';

    protected $guarded = [
        'numberOfArms',
    ];

    protected $fieldMapping = [
        'nameFirst' => 'name_first',
        'id' => 'primaryKey',
    ];
}
