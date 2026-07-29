<?php

namespace Tests\Models;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;

class Person extends FMModel
{
    protected $connection = 'filemaker';

    protected $table = 'person';

    protected $primaryKey = 'primaryKey';

    protected $guarded = [];

    protected $casts = [
        'nextAppointment' => 'timestamp',
        'prevAppointment' => 'datetime:Y-m-d H:i:s',
        'birthday' => 'date:Y-m-d',
    ];

    protected $fieldMapping = [
        'nameFirst' => 'name_first',
        'id' => 'primaryKey',
    ];

    public function car()
    {
        return $this->hasOne(Car::class, 'p_id', 'primaryKey');
    }

    public function cars()
    {
        return $this->hasMany(Car::class, 'p_id', 'primaryKey');
    }
}
