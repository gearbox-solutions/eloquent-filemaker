<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use InvalidArgumentException;
use Tests\TestCase;

class NestedWhereTest extends TestCase
{
    public function test_a_nested_or_group_is_distributed_across_find_requests()
    {
        $builder = FM::layout('pet')
            ->where('age', '>', 10)
            ->where(function ($query) {
                $query->where('name_first', 'michael')
                    ->orWhere('name_last', 'deck');
            });

        $this->assertEquals([
            ['age' => '>10', 'name_first' => 'michael'],
            ['age' => '>10', 'name_last' => 'deck'],
        ], $builder->getWheres());
    }

    public function test_a_nested_group_can_be_the_first_where()
    {
        $builder = FM::layout('pet')
            ->where(function ($query) {
                $query->where('name', 'Cosmo')
                    ->orWhere('name', 'Fluffy');
            });

        $this->assertEquals([
            ['name' => 'Cosmo'],
            ['name' => 'Fluffy'],
        ], $builder->getWheres());
    }

    public function test_an_or_where_nested_group_adds_its_find_requests()
    {
        $builder = FM::layout('pet')
            ->where('status', 'active')
            ->orWhere(function ($query) {
                $query->where('age', '<', 5)
                    ->where('type', 'cat');
            });

        $this->assertEquals([
            ['status' => 'active'],
            ['age' => '<5', 'type' => 'cat'],
        ], $builder->getWheres());
    }

    public function test_a_where_not_nested_group_adds_omit_find_requests()
    {
        $builder = FM::layout('pet')
            ->where('status', 'active')
            ->whereNot(function ($query) {
                $query->where('type', 'dog')
                    ->orWhere('type', 'cat');
            });

        $this->assertEquals([
            ['status' => 'active'],
            ['type' => 'dog', 'omit' => 'true'],
            ['type' => 'cat', 'omit' => 'true'],
        ], $builder->getWheres());
    }

    public function test_a_where_not_nested_group_containing_an_omit_is_not_supported()
    {
        $this->expectException(InvalidArgumentException::class);

        FM::layout('pet')
            ->whereNot(function ($query) {
                $query->where('type', 'dog')
                    ->orWhereNot('type', 'cat');
            });
    }

    public function test_a_where_in_inside_a_nested_group_is_distributed()
    {
        $builder = FM::layout('pet')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereIn('type', ['cat', 'dog']);
            });

        $this->assertEquals([
            ['status' => 'active', 'type' => 'cat'],
            ['status' => 'active', 'type' => 'dog'],
        ], $builder->getWheres());
    }

    public function test_a_nested_group_inside_a_nested_group_is_distributed()
    {
        $builder = FM::layout('pet')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('type', 'cat')
                    ->orWhere(function ($query) {
                        $query->where('type', 'dog')
                            ->where('age', '<', 5);
                    });
            });

        $this->assertEquals([
            ['status' => 'active', 'type' => 'cat'],
            ['status' => 'active', 'type' => 'dog', 'age' => '<5'],
        ], $builder->getWheres());
    }

    public function test_a_nested_group_after_an_omit_starts_a_new_find_request()
    {
        $builder = FM::layout('pet')
            ->whereNot('type', 'dog')
            ->where(function ($query) {
                $query->where('name', 'Cosmo')
                    ->orWhere('name', 'Fluffy');
            });

        $this->assertEquals([
            ['type' => 'dog', 'omit' => 'true'],
            ['name' => 'Cosmo'],
            ['name' => 'Fluffy'],
        ], $builder->getWheres());
    }

    public function test_an_empty_nested_group_is_ignored()
    {
        $builder = FM::layout('pet')
            ->where('status', 'active')
            ->where(function ($query) {
                // no conditions added
            });

        $this->assertEquals([
            ['status' => 'active'],
        ], $builder->getWheres());
    }

    public function test_a_nested_group_on_a_model_maps_field_names()
    {
        $query = NestedWherePet::where('age', '>', 10)
            ->where(function ($query) {
                $query->where('name', 'Cosmo')
                    ->orWhere('nickname', 'Fluffy');
            });

        $this->assertEquals([
            ['age' => '>10', 'pet name' => 'Cosmo'],
            ['age' => '>10', 'pet nickname' => 'Fluffy'],
        ], $query->getQuery()->getWheres());
    }
}

class NestedWherePet extends FMModel
{
    protected $layout = 'pet';

    protected $fieldMapping = [
        'pet name' => 'name',
        'pet nickname' => 'nickname',
    ];
}
