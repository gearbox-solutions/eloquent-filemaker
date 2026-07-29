<?php

namespace Tests\Feature;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Tests\Models\Car;
use Tests\Models\GuardedPerson;
use Tests\Models\Person;
use Tests\Models\Pet;
use Tests\Support\MocksDataApi;
use Tests\TestCase;

class EloquentWriteTest extends TestCase
{
    use MocksDataApi;

    protected function hydratedPet(): Pet
    {
        return Pet::createFromRecord($this->fmRecord([
            'id' => 'ABC-123',
            'name' => 'Cosmo',
            'type' => 'cat',
            'flagged' => 1,
        ], [], '879', '2'));
    }

    public function test_saving_a_new_model_creates_a_record_and_refreshes_it()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::sequence()
                // response to the create
                ->push($this->fmResultResponse(['recordId' => '100', 'modId' => '0']))
                // response to the refresh after the insert
                ->push($this->fmRecordsResponse([
                    $this->fmRecord(['id' => 'NEW-UUID', 'name' => 'Mr.Bigglesworth', 'flagged' => 1], [], '100', '0'),
                ])),
        ]);

        $pet = new Pet;
        $pet->petName = 'Mr.Bigglesworth';
        $pet->flagged = true;
        $pet->save();

        $this->assertTrue($pet->exists);
        $this->assertEquals('100', $pet->getRecordId());
        // the generated primary key is filled in from the post-insert refresh
        $this->assertEquals('NEW-UUID', $pet->id);

        $create = $this->recordedRequest($this->layoutUrl('pet') . '/records/', 'post');
        // attribute names are mapped back to FileMaker field names and booleans converted
        $this->assertEquals(['fieldData' => ['name' => 'Mr.Bigglesworth', 'flagged' => 1]], $this->bodyData($create));

        $this->recordedRequest($this->layoutUrl('pet') . '/records/100', 'get');
    }

    public function test_create_only_fills_fillable_attributes()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::sequence()
                ->push($this->fmResultResponse(['recordId' => '100', 'modId' => '0']))
                ->push($this->fmRecordsResponse([
                    $this->fmRecord(['id' => 'NEW-UUID', 'name' => 'myNewPet', 'type' => 'hamster'], [], '100', '0'),
                ])),
        ]);

        $pet = Pet::create([
            'name' => 'myNewPet',
            'type' => 'hamster',
            'serial' => 999,
        ]);

        $this->assertEquals('100', $pet->getRecordId());

        $create = $this->recordedRequest($this->layoutUrl('pet') . '/records/', 'post');
        // serial is not fillable and should not be sent
        $this->assertEquals(['fieldData' => ['name' => 'myNewPet', 'type' => 'hamster']], $this->bodyData($create));
    }

    public function test_guarded_attributes_are_not_written_to_filemaker()
    {
        $this->fakeDataApi([
            $this->layoutUrl('person') . '/records/*' => Http::sequence()
                ->push($this->fmResultResponse(['recordId' => '200', 'modId' => '0']))
                ->push($this->fmRecordsResponse([
                    $this->fmRecord(['id' => 'P-1', 'nameLast' => 'Armstrong'], [], '200', '0'),
                ])),
            // guarding specific fields makes the model check the layout's field names
            $this->layoutUrl('person') => Http::response($this->fmResultResponse([
                'fieldMetaData' => [
                    ['name' => 'nameLast'],
                    ['name' => 'numberOfArms'],
                ],
            ])),
        ]);

        $person = GuardedPerson::create([
            'numberOfArms' => 3,
            'nameLast' => 'Armstrong',
        ]);

        $this->assertEquals('200', $person->getRecordId());

        $create = $this->recordedRequest($this->layoutUrl('person') . '/records/', 'post');
        $this->assertEquals(['fieldData' => ['nameLast' => 'Armstrong']], $this->bodyData($create));
    }

    public function test_saving_an_existing_model_patches_only_dirty_fields()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '3'])),
        ]);

        $pet = $this->hydratedPet();
        $pet->petName = 'Cosmo Updated';

        $this->assertTrue($pet->save());

        $patch = $this->recordedRequest($this->layoutUrl('pet') . '/records/879', 'patch');
        $this->assertEquals(['fieldData' => ['name' => 'Cosmo Updated']], $this->bodyData($patch));

        // the modId is updated from the edit response
        $this->assertEquals('3', $pet->getModId());
    }

    public function test_saving_a_model_with_no_changes_sends_no_requests()
    {
        Http::fake();

        $pet = $this->hydratedPet();

        $this->assertTrue($pet->save());

        Http::assertNothingSent();
    }

    public function test_with_mod_id_includes_the_mod_id_when_editing()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '3'])),
        ]);

        $pet = $this->hydratedPet();
        $pet->petName = 'Cosmo Updated';
        $pet->withModId()->save();

        $patch = $this->recordedRequest($this->layoutUrl('pet') . '/records/879', 'patch');
        $this->assertEquals('2', $this->bodyData($patch)['modId']);
    }

    public function test_deleting_a_model_deletes_by_its_record_id()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse()),
        ]);

        $pet = $this->hydratedPet();

        $this->assertTrue($pet->delete());

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/879', 'delete');
        $this->assertEquals('DELETE', $request->method());
    }

    public function test_deleting_from_a_query_bulk_deletes_the_found_models()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Junk'], [], '7'),
                $this->fmRecord(['name' => 'Junk'], [], '8'),
            ])),
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse()),
        ]);

        $deletedCount = Pet::where('petName', 'Junk')->delete();

        $this->assertSame(2, $deletedCount);
        $this->assertCount(2, $this->recordedRequests($this->layoutUrl('pet') . '/records/', 'delete'));
    }

    public function test_duplicating_a_model_returns_the_new_record_id()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['recordId' => '900', 'modId' => '0'])),
        ]);

        $pet = $this->hydratedPet();

        $newRecordId = $pet->duplicate();

        $this->assertEquals('900', $newRecordId);
        $this->recordedRequest($this->layoutUrl('pet') . '/records/879', 'post');
    }

    public function test_refresh_reloads_the_model_attributes()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['id' => 'ABC-123', 'name' => 'Fresh Name'], [], '879', '5'),
            ])),
        ]);

        $pet = $this->hydratedPet();
        $pet->petName = 'Local Change';

        $pet->refresh();

        $this->assertEquals('Fresh Name', $pet->petName);
        $this->assertTrue($pet->isClean());

        $this->recordedRequest($this->layoutUrl('pet') . '/records/879', 'get');
    }

    public function test_setting_a_container_field_uploads_the_file_on_save()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '4'])),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        $pet = $this->hydratedPet();
        $pet->photo = new File($path);
        $pet->save();

        $upload = $this->recordedRequest($this->layoutUrl('pet') . '/records/879/containers/photo', 'post');
        $this->assertTrue($upload->hasFile('upload'));

        // no field data changed, so nothing should be patched
        $this->assertCount(0, $this->recordedRequests($this->layoutUrl('pet') . '/records/879', 'patch'));

        // the modId is updated from the container upload response
        $this->assertEquals('4', $pet->getModId());

        unlink($path);
    }

    public function test_json_fields_can_be_updated_with_arrow_syntax()
    {
        $this->fakeDataApi([
            $this->layoutUrl('car') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '2'])),
        ]);

        $car = Car::createFromRecord($this->fmRecord([
            'model' => 'Celica',
            'tech_specs_json' => '{"year":2000}',
        ], [], '10', '1'));

        $car->update(['tech_specs_json->year' => 2001]);

        $patch = $this->recordedRequest($this->layoutUrl('car') . '/records/10', 'patch');
        $this->assertEquals(['fieldData' => ['tech_specs_json' => '{"year":2001}']], $this->bodyData($patch));
        $this->assertEquals(2001, $car->tech_specs_json['year']);
    }

    public function test_modified_portal_data_is_sent_with_only_the_changed_fields()
    {
        $this->fakeDataApi([
            $this->layoutUrl('person') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '6'])),
        ]);

        $person = Person::createFromRecord($this->fmRecord([
            'id' => 'P-1',
            'nameFirst' => 'David',
        ], [
            'cars_named' => [
                ['per_CAR::model' => 'Celica', 'per_CAR::year' => 2001, 'recordId' => '11', 'modId' => '2'],
            ],
        ], '300', '5'));

        $cars = $person->cars_named;
        $cars[0]['per_CAR::model'] = 'Celicaa';
        $person->cars_named = $cars;
        $person->save();

        $patch = $this->recordedRequest($this->layoutUrl('person') . '/records/300', 'patch');
        $this->assertEquals([
            'portalData' => [
                'cars_named' => [
                    ['per_CAR::model' => 'Celicaa', 'recordId' => '11'],
                ],
            ],
        ], $this->bodyData($patch));
    }
}
