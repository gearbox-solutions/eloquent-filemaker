<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Tests\TestCase;

class NestedWhereTest extends TestCase
{
    public function test_a_nested_or_group_is_wrapped_in_parentheses()
    {
        $sql = FM::table('pet')
            ->where('age', '>', 10)
            ->where(function ($query) {
                $query->where('first', 'michael')
                    ->orWhere('last', 'deck');
            })->toSql();

        $this->assertSame("age gt 10 and (first eq 'michael' or last eq 'deck')", $sql);
    }

    public function test_a_nested_group_can_be_the_first_where()
    {
        $sql = FM::table('pet')
            ->where(function ($query) {
                $query->where('name', 'Cosmo')
                    ->orWhere('name', 'Fluffy');
            })->toSql();

        $this->assertSame("(name eq 'Cosmo' or name eq 'Fluffy')", $sql);
    }

    public function test_an_or_where_nested_group_is_ored_in()
    {
        $sql = FM::table('pet')
            ->where('status', 'active')
            ->orWhere(function ($query) {
                $query->where('age', '<', 5)
                    ->where('type', 'cat');
            })->toSql();

        $this->assertSame("status eq 'active' or (age lt 5 and type eq 'cat')", $sql);
    }

    public function test_a_where_not_nested_group_negates_the_whole_group()
    {
        $sql = FM::table('pet')
            ->where('status', 'active')
            ->whereNot(function ($query) {
                $query->where('type', 'dog')
                    ->orWhere('type', 'cat');
            })->toSql();

        $this->assertSame("status eq 'active' and not ((type eq 'dog' or type eq 'cat'))", $sql);
    }

    public function test_a_where_in_inside_a_nested_group_is_wrapped()
    {
        $sql = FM::table('pet')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereIn('type', ['cat', 'dog']);
            })->toSql();

        $this->assertSame("status eq 'active' and (type in ('cat','dog'))", $sql);
    }

    public function test_a_nested_group_inside_a_nested_group()
    {
        $sql = FM::table('pet')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('type', 'cat')
                    ->orWhere(function ($query) {
                        $query->where('type', 'dog')
                            ->where('age', '<', 5);
                    });
            })->toSql();

        $this->assertSame("status eq 'active' and (type eq 'cat' or (type eq 'dog' and age lt 5))", $sql);
    }

    public function test_a_nested_group_after_a_where_not_is_still_anded_together()
    {
        $sql = FM::table('pet')
            ->whereNot('type', 'dog')
            ->where(function ($query) {
                $query->where('name', 'Cosmo')
                    ->orWhere('name', 'Fluffy');
            })->toSql();

        $this->assertSame("not (type eq 'dog') and (name eq 'Cosmo' or name eq 'Fluffy')", $sql);
    }

    public function test_an_empty_nested_group_is_ignored()
    {
        $sql = FM::table('pet')
            ->where('status', 'active')
            ->where(function ($query) {
                // no conditions added
            })->toSql();

        $this->assertSame("status eq 'active'", $sql);
    }

    public function test_a_nested_group_on_a_model_maps_field_names()
    {
        $query = NestedWherePet::where('age', '>', 10)
            ->where(function ($query) {
                $query->where('name', 'Cosmo')
                    ->orWhere('nickname', 'Fluffy');
            });

        $this->assertSame(
            "age gt 10 and (\"pet name\" eq 'Cosmo' or \"pet nickname\" eq 'Fluffy')",
            $query->getQuery()->toSql()
        );
    }
}

class NestedWherePet extends FMModel
{
    protected $table = 'pet';

    protected $fieldMapping = [
        'pet name' => 'name',
        'pet nickname' => 'nickname',
    ];
}
