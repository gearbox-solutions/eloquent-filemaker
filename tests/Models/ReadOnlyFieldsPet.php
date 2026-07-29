<?php

namespace Tests\Models;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;

/**
 * A model with read-only fields which should never be written back to FileMaker.
 */
class ReadOnlyFieldsPet extends FMModel
{
    protected $connection = 'filemaker';

    protected $layout = 'pet';

    protected $guarded = [];

    protected $readOnlyFields = [
        'creationTimestamp',
        'serial',
    ];
}
