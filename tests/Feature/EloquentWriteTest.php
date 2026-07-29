<?php

namespace Tests\Feature;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Tests\Models\Car;
use Tests\Models\GuardedPerson;
use Tests\Models\Pet;
use Tests\Support\MocksOData;
use Tests\TestCase;

class EloquentWriteTest extends TestCase
{
    use MocksOData;

    protected function hydratedPet(): Pet
    {
        return Pet::createFromRecord([
            'id' => 'ABC-123',
            'name' => 'Cosmo',
            'type' => 'cat',
            'flagged' => 1,
        ]);
    }

    protected function personMetadataXml(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">
              <edmx:DataServices>
                <Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="Tester">
                  <EntityType Name="person">
                    <Property Name="nameLast" Type="Edm.String"/>
                    <Property Name="numberOfArms" Type="Edm.Int32"/>
                  </EntityType>
                </Schema>
              </edmx:DataServices>
            </edmx:Edmx>
            XML;
    }

    public function test_saving_a_new_model_creates_a_record_and_hydrates_from_the_response()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response(['id' => 'NEW-UUID', 'name' => 'Mr.Bigglesworth', 'flagged' => 1]),
        ]);

        $pet = new Pet;
        $pet->petName = 'Mr.Bigglesworth';
        $pet->flagged = true;
        $pet->save();

        $this->assertTrue($pet->exists);
        $this->assertEquals('NEW-UUID', $pet->id);
        $this->assertEquals('Mr.Bigglesworth', $pet->petName);

        // a single request creates the record; the OData response already contains the
        // generated primary key and any calculated field values, so no refresh is needed
        $create = $this->recordedRequest($this->tableUrl('pet'), 'post');
        $this->assertEquals(['name' => 'Mr.Bigglesworth', 'flagged' => 1], $this->bodyData($create));
    }

    public function test_create_only_fills_fillable_attributes()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response(['id' => 'NEW-UUID', 'name' => 'myNewPet', 'type' => 'hamster']),
        ]);

        $pet = Pet::create([
            'name' => 'myNewPet',
            'type' => 'hamster',
            'serial' => 999,
        ]);

        $this->assertEquals('NEW-UUID', $pet->id);

        $create = $this->recordedRequest($this->tableUrl('pet'), 'post');
        // serial is not fillable and should not be sent
        $this->assertEquals(['name' => 'myNewPet', 'type' => 'hamster'], $this->bodyData($create));
    }

    public function test_guarded_attributes_are_not_written_to_filemaker()
    {
        $this->fakeOData([
            $this->baseUrl() . '/$metadata' => Http::response($this->personMetadataXml()),
            $this->tableUrl('person') . '*' => Http::response(['id' => '200', 'nameLast' => 'Armstrong']),
        ]);

        $person = GuardedPerson::create([
            'numberOfArms' => 3,
            'nameLast' => 'Armstrong',
        ]);

        // GuardedPerson maps the 'id' FileMaker field to the 'primaryKey' attribute
        $this->assertEquals('200', $person->primaryKey);

        $create = $this->recordedRequest($this->tableUrl('person'), 'post');
        $this->assertEquals(['nameLast' => 'Armstrong'], $this->bodyData($create));
    }

    public function test_saving_an_existing_model_patches_only_dirty_fields()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(1),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $pet = $this->hydratedPet();
        $pet->petName = 'Cosmo Updated';

        $this->assertTrue($pet->save());

        $patch = $this->recordedRequest($this->tableUrl('pet'), 'patch');
        $this->assertEquals(['name' => 'Cosmo Updated'], $this->bodyData($patch));
        $this->assertEquals("id eq 'ABC-123'", $this->queryParams($patch)['$filter']);
    }

    public function test_saving_a_model_with_no_changes_sends_no_requests()
    {
        Http::fake();

        $pet = $this->hydratedPet();

        $this->assertTrue($pet->save());

        Http::assertNothingSent();
    }

    public function test_deleting_a_model_deletes_it_by_its_primary_key()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(1),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $pet = $this->hydratedPet();

        $this->assertTrue($pet->delete());

        $delete = $this->recordedRequest($this->tableUrl('pet'), 'delete');
        $this->assertEquals("id eq 'ABC-123'", $this->queryParams($delete)['$filter']);
    }

    public function test_deleting_from_a_query_bulk_deletes_the_matched_models()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(2),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $deletedCount = Pet::where('petName', 'Junk')->delete();

        $this->assertSame(2, $deletedCount);
        $this->assertCount(1, $this->recordedRequests($this->tableUrl('pet'), 'delete'));
    }

    public function test_refresh_reloads_the_model_attributes()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['id' => 'ABC-123', 'name' => 'Fresh Name'],
            ])),
        ]);

        $pet = $this->hydratedPet();
        $pet->petName = 'Local Change';

        $pet->refresh();

        $this->assertEquals('Fresh Name', $pet->petName);
        $this->assertTrue($pet->isClean());

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals("id eq 'ABC-123'", $params['$filter']);
    }

    public function test_setting_a_container_field_is_base64_encoded_in_the_update_patch()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(1),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        $pet = $this->hydratedPet();
        $pet->photo = new File($path);
        $pet->save();

        $patch = $this->recordedRequest($this->tableUrl('pet'), 'patch');
        $this->assertEquals(base64_encode('fake image data'), $this->bodyData($patch)['photo']);

        unlink($path);
    }

    public function test_json_fields_can_be_updated_with_arrow_syntax()
    {
        $this->fakeOData([
            $this->tableUrl('car') . '/$count*' => $this->odataCountResponse(1),
            $this->tableUrl('car') . '*' => Http::response([]),
        ]);

        $car = Car::createFromRecord([
            'model' => 'Celica',
            'tech_specs_json' => '{"year":2000}',
        ]);
        $car->id = '10';
        $car->syncOriginal();

        $car->update(['tech_specs_json->year' => 2001]);

        $patch = $this->recordedRequest($this->tableUrl('car'), 'patch');
        $this->assertEquals(['tech_specs_json' => '{"year":2001}'], $this->bodyData($patch));
        $this->assertEquals(2001, $car->tech_specs_json['year']);
    }
}
