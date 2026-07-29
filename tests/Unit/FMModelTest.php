<?php

namespace Tests\Unit;

use DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Models\Person;
use Tests\Models\Pet;
use Tests\Models\ReadOnlyFieldsPet;
use Tests\Support\MocksDataApi;
use Tests\TestCase;

class FMModelTest extends TestCase
{
    use MocksDataApi;

    protected function cosmoRecord(): array
    {
        return $this->fmRecord([
            'id' => 'ABC-123',
            'name' => 'Cosmo',
            'type' => 'cat',
            'flagged' => 1,
        ], [
            'pets' => [
                ['per_PET::name' => 'Fido', 'recordId' => '55', 'modId' => '1'],
            ],
        ], '879', '2');
    }

    public function test_create_from_record_hydrates_a_model()
    {
        $pet = Pet::createFromRecord($this->cosmoRecord());

        $this->assertEquals('ABC-123', $pet->id);
        $this->assertEquals('cat', $pet->type);
        $this->assertEquals('879', $pet->getRecordId());
        $this->assertEquals('2', $pet->getModId());
        $this->assertTrue($pet->exists);
        $this->assertTrue($pet->isClean());
    }

    public function test_create_from_record_maps_filemaker_fields_to_model_attributes()
    {
        $pet = Pet::createFromRecord($this->cosmoRecord());

        // the FileMaker field 'name' is mapped to the 'petName' attribute
        $this->assertEquals('Cosmo', $pet->petName);
        $this->assertNull($pet->name);
    }

    public function test_create_from_record_includes_portal_data()
    {
        $pet = Pet::createFromRecord($this->cosmoRecord());

        $this->assertEquals([
            ['per_PET::name' => 'Fido', 'recordId' => '55', 'modId' => '1'],
        ], $pet->pets);
    }

    public function test_create_from_record_converts_empty_strings_to_null()
    {
        $pet = Pet::createFromRecord($this->fmRecord(['name' => 'Cosmo', 'type' => '']));

        $this->assertNull($pet->type);
    }

    public function test_empty_string_conversion_can_be_disabled_in_the_connection_config()
    {
        Config::set('database.connections.filemaker.empty_strings_to_null', false);
        DB::purge('filemaker');

        $pet = Pet::createFromRecord($this->fmRecord(['name' => 'Cosmo', 'type' => '']));

        $this->assertSame('', $pet->type);
    }

    public function test_create_models_from_record_set_returns_an_eloquent_collection()
    {
        $models = Pet::createModelsFromRecordSet(collect([
            $this->fmRecord(['name' => 'Cosmo']),
            $this->fmRecord(['name' => 'Fido'], [], '2'),
        ]));

        $this->assertInstanceOf(Collection::class, $models);
        $this->assertCount(2, $models);
        $this->assertEquals(['Cosmo', 'Fido'], $models->pluck('petName')->all());
    }

    public function test_create_models_from_an_empty_record_set_returns_an_empty_collection()
    {
        $models = Pet::createModelsFromRecordSet(collect([]));

        $this->assertInstanceOf(Collection::class, $models);
        $this->assertCount(0, $models);
    }

    public function test_empty_strings_are_cast_to_null_for_cast_attributes()
    {
        $pet = Pet::createFromRecord($this->fmRecord(['name' => 'Cosmo', 'creationTimestamp' => '']));

        $this->assertNull($pet->creationTimestamp);
    }

    public function test_boolean_attributes_are_converted_to_ones_and_zeros()
    {
        $pet = new Pet;

        $pet->flagged = true;
        $this->assertSame(1, $pet->getAttributes()['flagged']);

        $pet->flagged = false;
        $this->assertSame(0, $pet->getAttributes()['flagged']);
    }

    public function test_date_cast_attributes_are_stored_as_date_only_strings()
    {
        $person = new Person;

        $person->birthday = new Carbon('1986-07-22');

        // the model's date format is 'm/j/Y H:i:s' and date casts strip the time portion
        $this->assertSame('07/22/1986', $person->getAttributes()['birthday']);
        $this->assertEquals('1986-07-22', $person->birthday->format('Y-m-d'));
    }

    public function test_datetime_objects_are_stored_as_formatted_strings()
    {
        $person = new Person;

        $person->prevAppointment = new DateTime('1986-07-21 19:20:00');

        $this->assertSame('07/21/1986 19:20:00', $person->getAttributes()['prevAppointment']);
    }

    public function test_get_layout_is_used_as_the_table_name()
    {
        $pet = new Pet;

        $this->assertEquals('pet', $pet->getTable());
        $this->assertEquals('pet', $pet->getLayout());
    }

    public function test_columns_are_not_qualified_with_a_table_name()
    {
        $this->assertEquals('name', (new Pet)->qualifyColumn('name'));
    }

    public function test_attributes_for_filemaker_write_only_include_dirty_fields()
    {
        $pet = Pet::createFromRecord($this->cosmoRecord());

        $pet->type = 'dog';

        $this->assertEquals(['type' => 'dog'], $pet->getAttributesForFileMakerWrite()->toArray());
    }

    public function test_read_only_fields_are_not_written_to_filemaker()
    {
        $pet = new ReadOnlyFieldsPet;
        $pet->name = 'Cosmo';
        $pet->serial = 100;
        $pet->creationTimestamp = 'some timestamp';

        $this->assertEquals(['name' => 'Cosmo'], $pet->getAttributesForFileMakerWrite()->toArray());
    }

    public function test_container_fields_are_written_separately_from_field_data()
    {
        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        $pet = new Pet;
        $pet->petName = 'Cosmo';
        $pet->photo = new File($path);

        $this->assertEquals(['petName' => 'Cosmo'], $pet->getAttributesForFileMakerWrite()->toArray());
        $this->assertEquals(['photo'], $pet->getContainersToWrite()->toArray());

        unlink($path);
    }

    public function test_a_file_with_a_custom_file_name_is_treated_as_a_container()
    {
        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        $pet = new Pet;
        $pet->photo = [new File($path), 'fluffy.jpg'];

        $this->assertEquals(['photo'], $pet->getContainersToWrite()->toArray());

        unlink($path);
    }

    public function test_with_mod_id_can_set_a_mod_id_directly()
    {
        $pet = new Pet;

        $this->assertFalse($pet->usingModId());

        $pet->withModId(5);

        $this->assertTrue($pet->usingModId());
        $this->assertEquals(5, $pet->getModId());

        $pet->withModId(false);
        $this->assertFalse($pet->usingModId());
    }

    public function test_duplicating_an_unsaved_model_returns_false()
    {
        $this->assertFalse((new Pet)->duplicate());
    }
}
