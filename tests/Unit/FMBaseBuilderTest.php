<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class FMBaseBuilderTest extends TestCase
{
    public function test_where_with_two_arguments_defaults_to_equals()
    {
        $sql = FM::table('pet')->where('name', 'Cosmo')->toSql();

        $this->assertSame("name eq 'Cosmo'", $sql);
    }

    public function test_where_with_an_operator_is_translated_to_odata()
    {
        $sql = FM::table('pet')->where('serial', '>', 500)->toSql();

        $this->assertSame('serial gt 500', $sql);
    }

    public function test_chained_wheres_combine_with_and()
    {
        $sql = FM::table('pet')->where('name', 'Cosmo')->where('type', 'cat')->toSql();

        $this->assertSame("name eq 'Cosmo' and type eq 'cat'", $sql);
    }

    public function test_or_where_combines_with_or()
    {
        $sql = FM::table('pet')->where('name', 'Cosmo')->orWhere('name', 'Fido')->toSql();

        $this->assertSame("name eq 'Cosmo' or name eq 'Fido'", $sql);
    }

    public function test_where_not_negates_and_parenthesizes_the_condition()
    {
        $sql = FM::table('pet')->whereNot('name', 'Cosmo')->toSql();

        $this->assertSame("not (name eq 'Cosmo')", $sql);
    }

    public function test_where_after_where_not_is_still_anded_together()
    {
        $sql = FM::table('pet')->whereNot('name', 'Cosmo')->where('type', 'cat')->toSql();

        $this->assertSame("not (name eq 'Cosmo') and type eq 'cat'", $sql);
    }

    public function test_where_in_compiles_to_an_in_expression()
    {
        $sql = FM::table('pet')->whereIn('name', ['Cosmo', 'Fido'])->toSql();

        $this->assertSame("name in ('Cosmo','Fido')", $sql);
    }

    public function test_where_not_in_compiles_to_a_negated_in_expression()
    {
        $sql = FM::table('pet')->whereNotIn('name', ['Cosmo', 'Fido'])->toSql();

        $this->assertSame("not (name in ('Cosmo','Fido'))", $sql);
    }

    public function test_where_in_with_no_values_compiles_to_a_literal_false()
    {
        $sql = FM::table('pet')->whereIn('name', [])->toSql();

        $this->assertSame('false', $sql);
    }

    public function test_where_not_in_with_no_values_compiles_to_a_literal_true()
    {
        $sql = FM::table('pet')->whereNotIn('name', [])->toSql();

        $this->assertSame('true', $sql);
    }

    public function test_where_between_builds_a_range_query()
    {
        $sql = FM::table('pet')->whereBetween('serial', [550, 552])->toSql();

        $this->assertSame('(serial ge 550 and serial le 552)', $sql);
    }

    public function test_where_not_between_negates_the_range_query()
    {
        $sql = FM::table('pet')->whereNotBetween('serial', [550, 552])->toSql();

        $this->assertSame('not (serial ge 550 and serial le 552)', $sql);
    }

    public function test_where_null_matches_null_or_empty_string()
    {
        $sql = FM::table('pet')->whereNull('name')->toSql();

        $this->assertSame("(name eq null or name eq '')", $sql);
    }

    public function test_where_not_null_excludes_null_and_empty_string()
    {
        $sql = FM::table('pet')->whereNotNull('name')->toSql();

        $this->assertSame("(name ne null and name ne '')", $sql);
    }

    public function test_where_like_with_wildcards_on_both_sides_uses_contains()
    {
        $sql = FM::table('pet')->where('name', 'like', '%osm%')->toSql();

        $this->assertSame("contains(name, 'osm')", $sql);
    }

    public function test_where_like_with_a_trailing_wildcard_uses_startswith()
    {
        $sql = FM::table('pet')->where('name', 'like', 'Cos%')->toSql();

        $this->assertSame("startswith(name, 'Cos')", $sql);
    }

    public function test_where_like_with_a_wildcard_in_the_middle_throws()
    {
        $this->expectException(InvalidArgumentException::class);

        FM::table('pet')->where('name', 'like', '%os%mo%')->toSql();
    }

    public function test_where_like_with_a_leading_wildcard_uses_endswith()
    {
        $sql = FM::table('pet')->where('name', 'like', '%smo')->toSql();

        $this->assertSame("endswith(name, 'smo')", $sql);
    }

    public function test_where_date_formats_datetime_values_for_the_filter()
    {
        $sql = FM::table('person')->whereDate('birthday', new Carbon('1986-07-20'))->toSql();

        $this->assertSame("birthday eq '1986-07-20'", $sql);
    }

    public function test_where_date_with_an_operator()
    {
        $sql = FM::table('person')->whereDate('birthday', '>', new Carbon('1986-07-20'))->toSql();

        $this->assertSame("birthday gt '1986-07-20'", $sql);
    }

    public function test_field_names_with_special_characters_are_quoted()
    {
        $sql = FM::table('pet')->where('first name', 'Cosmo')->toSql();

        $this->assertSame('"first name" eq \'Cosmo\'', $sql);
    }

    public function test_order_by_builds_an_orderby_expression()
    {
        $builder = FM::table('pet')->orderBy('name')->orderBy('serial', 'desc');

        $this->assertSame([
            ['column' => 'name', 'direction' => 'asc'],
            ['column' => 'serial', 'direction' => 'desc'],
        ], $builder->orders);
    }

    public function test_order_by_accepts_filemaker_ascend_descend_vocabulary()
    {
        $builder = FM::table('pet')->orderBy('name', FMBaseBuilder::ASCEND)->orderByDesc('serial');

        $this->assertSame('asc', $builder->orders[0]['direction']);
        $this->assertSame('desc', $builder->orders[1]['direction']);
    }

    public function test_sort_is_an_alias_for_order_by()
    {
        $builder = FM::table('pet')->sort('name', FMBaseBuilder::DESCEND);

        $this->assertSame('desc', $builder->orders[0]['direction']);
    }

    public function test_limit_and_offset_are_set_on_the_builder()
    {
        $builder = FM::table('pet')->limit(10)->offset(5);

        $this->assertSame(10, $builder->limit);
        $this->assertSame(5, $builder->offset);
    }

    public function test_field_mapping_remaps_where_columns_to_filemaker_field_names()
    {
        $builder = FM::table('pet');
        $builder->setFieldMapping(['name' => 'petName']);

        $sql = $builder->where('petName', 'Cosmo')->toSql();

        $this->assertSame("name eq 'Cosmo'", $sql);
    }

    public function test_field_mapping_remaps_where_in_and_order_by_columns()
    {
        $builder = FM::table('pet');
        $builder->setFieldMapping(['name' => 'petName']);

        $builder->whereIn('petName', ['Cosmo'])->orderBy('petName');

        $this->assertSame("name in ('Cosmo')", $builder->toSql());
        $this->assertSame([
            ['column' => 'petName', 'direction' => 'asc'],
        ], $builder->orders);
    }

    public function test_field_data_remaps_columns_to_filemaker_field_names()
    {
        $builder = FM::table('pet');
        $builder->setFieldMapping(['name' => 'petName']);

        $builder->fieldData(['petName' => 'Cosmo', 'type' => 'cat']);

        $this->assertSame(['name' => 'Cosmo', 'type' => 'cat'], $builder->fieldData);
    }

    public function test_to_sql_returns_the_compiled_filter()
    {
        $sql = FM::table('pet')->where('type', 'cat')->whereIn('name', ['Cosmo', 'Fido'])->toSql();

        $this->assertSame("type eq 'cat' and name in ('Cosmo','Fido')", $sql);
    }
}
