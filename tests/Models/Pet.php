<?php

namespace Tests\Models;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;
use Illuminate\Database\Eloquent\Builder;

class Pet extends FMModel
{
    protected $connection = 'filemaker';

    protected $layout = 'pet';

    protected $keyType = 'string';

    protected $casts = [
        'creationTimestamp' => 'datetime',
    ];

    // FileMaker Field Name => Model Attribute
    protected $fieldMapping = [
        'name' => 'petName',
    ];

    protected $fillable = [
        'name',
        'type',
        'flagged',
    ];

    protected static function booted()
    {
        static::addGlobalScope('flagged', function (Builder $builder) {
            $builder->where('flagged', 1);
        });
    }

    public function scopeCats($query)
    {
        $query->where('type', 'cat');
    }
}
