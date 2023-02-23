<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;
use Tests\TestCase;

class FMModelTest extends TestCase
{
    public function testDateAttributesAreCastCorrectly()
    {
        $model = new TestModel();

        $now = now();

        $model->DateField = $now;
        $model->DateTimeField = $now;
        $model->CustomDateTimeField = $now;

        $this->assertEquals(
            $now->format('n/j/Y'),
            $model->getAttributesForFileMakerWrite()['DateField']
        );

        $this->assertEquals(
            $now->format('n/j/Y g:i:s A'),
            $model->getAttributesForFileMakerWrite()['DateTimeField']
        );

        $this->assertEquals(
            $now->format('n/j/Y g:i:s A'),
            $model->getAttributesForFileMakerWrite()['CustomDateTimeField']
        );
    }
}

/**
 * @property \Illuminate\Support\Carbon $DateField
 * @property \Illuminate\Support\Carbon $DateTimeField
 * @property \Illuminate\Support\Carbon $CustomDateTimeField
 */
class TestModel extends FMModel
{
    protected $casts = [
        'DateField' => 'date',
        'DateTimeField' => 'datetime',
        'CustomDateTimeField' => 'date:Y-m-d',
    ];
}
