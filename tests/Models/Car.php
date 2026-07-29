<?php

namespace Tests\Models;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;

class Car extends FMModel
{
    protected $connection = 'filemaker';

    protected $layout = 'car';

    protected $guarded = [];

    protected $fieldMapping = [
        'person_id' => 'p_id',
    ];

    protected $casts = [
        'tech_specs_json' => 'json',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class, 'p_id', 'primaryKey');
    }
}
