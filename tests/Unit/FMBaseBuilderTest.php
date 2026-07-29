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
        $wheres = FM::table('pet')->where('name', 'Cosmo')->getWheres();

        $this->assertSame([['name' => 'Cosmo']], $wheres);
    }

    public function test_where_with_an_operator_prepends_the_operator_to_the_value()
    {
        $wheres = FM::table('pet')->where('serial', '>', 500)->getWheres();

        $this->assertSame([['serial' => '>500']], $wheres);
    }

    public function test_chained_wheres_combine_into_a_single_find_request()
    {
        $wheres = FM::table('pet')->where('name', 'Cosmo')->where('type', 'cat')->getWheres();

        $this->assertSame([['name' => 'Cosmo', 'type' => 'cat']], $wheres);
    }

    public function test_or_where_creates_a_second_find_request()
    {
        $wheres = FM::table('pet')->where('name', 'Cosmo')->orWhere('name', 'Fido')->getWheres();

        $this->assertSame([['name' => 'Cosmo'], ['name' => 'Fido']], $wheres);
    }

    public function test_where_not_creates_an_omit_request()
    {
        $wheres = FM::table('pet')->whereNot('name', 'Cosmo')->getWheres();

        $this->assertSame([['omit' => 'true', 'name' => 'Cosmo']], $wheres);
    }

    public function test_where_after_where_not_starts_a_new_find_request()
    {
        $wheres = FM::table('pet')->whereNot('name', 'Cosmo')->where('type', 'cat')->getWheres();

        $this->assertSame([['omit' => 'true', 'name' => 'Cosmo'], ['type' => 'cat']], $wheres);
    }

    public function test_where_not_after_where_starts_a_new_omit_request()
    {
        $wheres = FM::table('pet')->where('type', 'cat')->whereNot('name', 'Cosmo')->getWheres();

        $this->assertSame([['type' => 'cat'], ['omit' => 'true', 'name' => 'Cosmo']], $wheres);
    }

    public function test_chained_where_nots_combine_into_a_single_omit_request()
    {
        $wheres = FM::table('pet')->whereNot('name', 'Cosmo')->whereNot('serial', '>500')->getWheres();

        $this->assertSame([['omit' => 'true', 'name' => 'Cosmo', 'serial' => '>500']], $wheres);
    }

    public function test_or_where_not_creates_a_separate_omit_request()
    {
        $wheres = FM::table('pet')->whereNot('name', 'Cosmo')->orWhereNot('serial', '>500')->getWheres();

        $this->assertSame([['omit' => 'true', 'name' => 'Cosmo'], ['omit' => 'true', 'serial' => '>500']], $wheres);
    }

    public function test_omit_can_be_removed_from_the_current_find_request()
    {
        $wheres = FM::table('pet')->where('name', 'Cosmo')->omit()->omit(false)->getWheres();

        $this->assertSame([['name' => 'Cosmo', 'omit' => 'false']], $wheres);
    }

    public function test_where_with_an_associative_array_combines_into_one_find_request()
    {
        $wheres = FM::table('pet')->where(['name' => 'Cosmo', 'type' => 'cat'])->getWheres();

        $this->assertSame([['name' => 'Cosmo', 'type' => 'cat']], $wheres);
    }

    public function test_where_with_an_array_of_arrays_applies_each_condition()
    {
        $wheres = FM::table('pet')->where([['serial', '>', 500], ['name', 'Cosmo']])->getWheres();

        $this->assertSame([['serial' => '>500', 'name' => 'Cosmo']], $wheres);
    }

    public function test_where_in_expands_to_one_find_request_per_value()
    {
        $wheres = FM::table('pet')->whereIn('name', ['Cosmo', 'Fido'])->getWheres();

        $this->assertSame([['name' => 'Cosmo'], ['name' => 'Fido']], $wheres);
    }

    public function test_where_in_after_a_where_cross_joins_with_the_current_find_request()
    {
        $wheres = FM::table('pet')->where('type', 'cat')->whereIn('name', ['Cosmo', 'Fido'])->getWheres();

        $this->assertSame([
            ['type' => 'cat', 'name' => 'Cosmo'],
            ['type' => 'cat', 'name' => 'Fido'],
        ], $wheres);
    }

    public function test_or_where_in_adds_additional_find_requests()
    {
        $wheres = FM::table('pet')->where('type', 'cat')->orWhereIn('name', ['Cosmo', 'Fido'])->getWheres();

        $this->assertSame([
            ['type' => 'cat'],
            ['name' => 'Cosmo'],
            ['name' => 'Fido'],
        ], $wheres);
    }

    public function test_where_in_with_no_values_forces_an_empty_result_set()
    {
        $builder = FM::table('pet')->whereIn('name', []);

        $this->assertSame([['name' => '=']], $builder->getWheres());
        $this->assertTrue($builder->isForcingHighOffset());
    }

    public function test_where_between_builds_a_range_query()
    {
        $wheres = FM::table('pet')->whereBetween('serial', [550, 552])->getWheres();

        $this->assertSame([['serial' => '550...552']], $wheres);
    }

    public function test_where_null_finds_empty_fields()
    {
        $wheres = FM::table('pet')->whereNull('name')->getWheres();

        $this->assertSame([['name' => '=']], $wheres);
    }

    public function test_where_not_null_finds_non_empty_fields()
    {
        $wheres = FM::table('pet')->whereNotNull('name')->getWheres();

        $this->assertSame([['name' => '*']], $wheres);
    }

    public function test_where_date_formats_datetime_values_for_filemaker()
    {
        $wheres = FM::table('person')->whereDate('birthday', new Carbon('1986-07-20'))->getWheres();

        $this->assertSame([['birthday' => '=7/20/1986']], $wheres);
    }

    public function test_where_date_with_an_operator()
    {
        $wheres = FM::table('person')->whereDate('birthday', '>', new Carbon('1986-07-20'))->getWheres();

        $this->assertSame([['birthday' => '>7/20/1986']], $wheres);
    }

    public function test_where_with_an_invalid_operator_and_value_combination_throws()
    {
        $this->expectException(InvalidArgumentException::class);

        FM::table('pet')->where('name', '>', null);
    }

    public function test_order_by_builds_filemaker_sort_orders()
    {
        $builder = FM::table('pet')->orderBy('name')->orderBy('serial', 'desc');

        $this->assertSame([
            ['fieldName' => 'name', 'sortOrder' => 'ascend'],
            ['fieldName' => 'serial', 'sortOrder' => 'descend'],
        ], $builder->orders);
    }

    public function test_order_by_converts_laravel_sort_directions()
    {
        $builder = FM::table('pet')->orderBy('name', 'asc')->orderByDesc('serial');

        $this->assertSame([
            ['fieldName' => 'name', 'sortOrder' => 'ascend'],
            ['fieldName' => 'serial', 'sortOrder' => 'descend'],
        ], $builder->orders);
    }

    public function test_sort_is_an_alias_for_order_by()
    {
        $builder = FM::table('pet')->sort('name', FMBaseBuilder::DESCEND);

        $this->assertSame([['fieldName' => 'name', 'sortOrder' => 'descend']], $builder->orders);
    }

    public function test_limit_and_offset_are_set_on_the_builder()
    {
        $builder = FM::table('pet')->limit(10)->offset(5);

        $this->assertSame(10, $builder->limit);
        $this->assertSame(5, $builder->offset);
    }

    public function test_script_methods_set_script_properties()
    {
        $builder = FM::table('pet')
            ->script('my script', 'my param')
            ->scriptPresort('presort script', 'presort param')
            ->scriptPrerequest('prerequest script', 'prerequest param')
            ->layoutResponse('other layout');

        $this->assertSame('my script', $builder->script);
        $this->assertSame('my param', $builder->scriptParam);
        $this->assertSame('presort script', $builder->scriptPresort);
        $this->assertSame('presort param', $builder->scriptPresortParam);
        $this->assertSame('prerequest script', $builder->scriptPrerequest);
        $this->assertSame('prerequest param', $builder->scriptPrerequestParam);
        $this->assertSame('other layout', $builder->layoutResponse);
    }

    public function test_script_params_can_be_set_in_chained_calls()
    {
        $builder = FM::table('pet')
            ->script('my script')
            ->scriptParam('my param')
            ->scriptPresort('presort script')
            ->scriptPresortParam('presort param')
            ->scriptPrerequest('prerequest script')
            ->scriptPrerequestParam('prerequest param');

        $this->assertSame('my param', $builder->scriptParam);
        $this->assertSame('presort param', $builder->scriptPresortParam);
        $this->assertSame('prerequest param', $builder->scriptPrerequestParam);
    }

    public function test_portal_appends_a_single_portal_name()
    {
        $builder = FM::table('person')->portal('pets')->portal('cars');

        $this->assertSame(['pets', 'cars'], $builder->portal);
    }

    public function test_portal_with_an_array_replaces_the_portal_list()
    {
        $builder = FM::table('person')->portal('pets')->portal(['cars', 'houses']);

        $this->assertSame(['cars', 'houses'], $builder->portal);
    }

    public function test_limit_portal_and_offset_portal_track_portal_settings()
    {
        $builder = FM::table('person')
            ->limitPortal('cars', 3)
            ->offsetPortal('cars', 2);

        $this->assertSame([['portalName' => 'cars', 'limit' => 3]], $builder->limitPortals);
        $this->assertSame([['portalName' => 'cars', 'offset' => 2]], $builder->offsetPortals);
    }

    public function test_layout_sets_the_from_table()
    {
        $builder = FM::table('x')->layout('pet');

        $this->assertSame('pet', $builder->from);
    }

    public function test_record_id_and_mod_id_are_set_on_the_builder()
    {
        $builder = FM::table('pet')->recordId(123)->modId('4');

        $this->assertSame(123, $builder->getRecordId());
        $this->assertSame('4', $builder->modId);
    }

    public function test_field_mapping_remaps_where_columns_to_filemaker_field_names()
    {
        $builder = FM::table('pet');
        $builder->setFieldMapping(['name' => 'petName']);

        $wheres = $builder->where('petName', 'Cosmo')->getWheres();

        $this->assertSame([['name' => 'Cosmo']], $wheres);
    }

    public function test_field_mapping_remaps_sort_and_where_in_columns()
    {
        $builder = FM::table('pet');
        $builder->setFieldMapping(['name' => 'petName']);

        $builder->whereIn('petName', ['Cosmo'])->orderBy('petName');

        $this->assertSame([['name' => 'Cosmo']], $builder->getWheres());
        $this->assertSame([['fieldName' => 'name', 'sortOrder' => 'ascend']], $builder->orders);
    }

    public function test_field_data_remaps_columns_to_filemaker_field_names()
    {
        $builder = FM::table('pet');
        $builder->setFieldMapping(['name' => 'petName']);

        $builder->fieldData(['petName' => 'Cosmo', 'type' => 'cat']);

        $this->assertSame(['name' => 'Cosmo', 'type' => 'cat'], $builder->fieldData);
    }

    public function test_to_sql_returns_the_computed_find_requests_as_json()
    {
        $sql = FM::table('pet')->where('type', 'cat')->whereIn('name', ['Cosmo', 'Fido'])->toSql();

        $this->assertSame(json_encode([
            ['type' => 'cat', 'name' => 'Cosmo'],
            ['type' => 'cat', 'name' => 'Fido'],
        ]), $sql);
    }
}
