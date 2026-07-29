<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Tests\Models\Car;
use Tests\Models\Person;
use Tests\Models\Pet;
use Tests\Support\MocksOData;
use Tests\TestCase;

class EloquentQueryTest extends TestCase
{
    use MocksOData;

    public function test_a_model_query_sends_a_filtered_request_and_hydrates_models()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['id' => 'ABC-123', 'name' => 'Cosmo', 'type' => 'cat'],
            ])),
        ]);

        $pets = Pet::where('petName', 'Cosmo')->get();

        $this->assertInstanceOf(Collection::class, $pets);
        $this->assertInstanceOf(Pet::class, $pets[0]);
        $this->assertEquals('Cosmo', $pets[0]->petName);
        $this->assertTrue($pets[0]->exists);

        // the query should use the FileMaker field name and include the global scope
        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals("name eq 'Cosmo' and flagged eq 1", $params['$filter']);
    }

    public function test_global_scopes_are_applied_to_queries_without_wheres()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        Pet::query()->get();

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals('flagged eq 1', $params['$filter']);
    }

    public function test_without_global_scopes_sends_no_filter()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        Pet::withoutGlobalScopes()->get();

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertArrayNotHasKey('$filter', $params);
    }

    public function test_global_scopes_combine_with_an_or_where()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        Pet::where('petName', 'Cosmo')->orWhere('petName', 'Astrid')->get();

        // Eloquent wraps the pre-existing wheres in a group before ANDing in the global
        // scope, so the scope correctly applies to both branches of the "or"
        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals("(name eq 'Cosmo' or name eq 'Astrid') and flagged eq 1", $params['$filter']);
    }

    public function test_local_scopes_combine_with_wheres_and_where_ins()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        Pet::withoutGlobalScopes()
            ->where('flagged', 1)
            ->cats()
            ->whereIn('petName', ['Cosmo', 'Astrid', 'Scooter'])
            ->get();

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals("flagged eq 1 and type eq 'cat' and name in ('Cosmo','Astrid','Scooter')", $params['$filter']);
    }

    public function test_find_queries_by_the_primary_key()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['id' => 'ABC-123', 'name' => 'Cosmo'],
            ])),
        ]);

        $pet = Pet::find('ABC-123');

        $this->assertEquals('ABC-123', $pet->id);

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals("id eq 'ABC-123' and flagged eq 1", $params['$filter']);
    }

    public function test_find_or_fail_throws_when_no_records_match()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        $this->expectException(ModelNotFoundException::class);

        Pet::findOrFail('nothing');
    }

    public function test_first_limits_the_query_to_a_single_record()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo'],
            ])),
        ]);

        $pet = Pet::where('petName', 'Cosmo')->first();

        $this->assertInstanceOf(Pet::class, $pet);

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals('1', $params['$top']);
    }

    public function test_value_returns_a_single_attribute_from_the_first_record()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo', 'type' => 'cat'],
            ])),
        ]);

        $this->assertEquals('cat', Pet::where('petName', 'Cosmo')->value('type'));
    }

    public function test_count_returns_the_matching_count()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(25),
        ]);

        $this->assertSame(25, Pet::query()->count());
    }

    public function test_paginate_returns_a_paginator_of_models()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo'],
                ['name' => 'Astrid'],
            ], 12)),
        ]);

        $paginator = Pet::paginate(2);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertEquals(12, $paginator->total());
        $this->assertCount(2, $paginator->items());
        $this->assertInstanceOf(Pet::class, $paginator->items()[0]);
        $this->assertEquals('Cosmo', $paginator->items()[0]->petName);
    }

    public function test_where_key_not_excludes_the_primary_key()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        Pet::withoutGlobalScopes()->whereKeyNot('ABC-123')->get();

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals("id ne 'ABC-123'", $params['$filter']);
    }

    public function test_exists_is_true_when_records_are_found()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo'],
            ])),
        ]);

        $this->assertTrue(Pet::where('petName', 'Cosmo')->exists());
        $this->assertFalse(Pet::where('petName', 'Cosmo')->doesntExist());
    }

    public function test_exists_is_false_when_no_records_match()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        $this->assertFalse(Pet::where('petName', 'Nobody')->exists());
        $this->assertTrue(Pet::where('petName', 'Nobody')->doesntExist());
    }

    public function test_has_many_queries_the_related_table_by_foreign_key()
    {
        $this->fakeOData([
            $this->tableUrl('car') . '*' => Http::response($this->odataListResponse([
                ['model' => 'Celica', 'p_id' => 'P-1'],
                ['model' => 'Supra', 'p_id' => 'P-1'],
            ])),
        ]);

        $person = Person::createFromRecord(['primaryKey' => 'P-1', 'name_first' => 'David']);

        $cars = $person->cars;

        $this->assertCount(2, $cars);
        $this->assertInstanceOf(Car::class, $cars[0]);
        $this->assertEquals('Celica', $cars[0]->model);

        // Car's fieldMapping maps the 'p_id' attribute to the 'person_id' FileMaker field,
        // which contains an underscore and so is quoted
        $params = $this->queryParams($this->recordedRequest($this->tableUrl('car'), 'get'));
        $this->assertEquals("\"person_id\" eq 'P-1'", $params['$filter']);
    }

    public function test_belongs_to_queries_the_owner_by_its_primary_key()
    {
        $this->fakeOData([
            $this->tableUrl('person') . '*' => Http::response($this->odataListResponse([
                ['id' => 'P-1', 'name_first' => 'David'],
            ])),
        ]);

        $car = Car::createFromRecord(['model' => 'Celica', 'p_id' => 'P-1']);

        $person = $car->person;

        $this->assertInstanceOf(Person::class, $person);
        $this->assertEquals('David', $person->name_first);

        // Person maps the 'id' FileMaker field to the primaryKey attribute
        $params = $this->queryParams($this->recordedRequest($this->tableUrl('person'), 'get'));
        $this->assertEquals("id eq 'P-1'", $params['$filter']);
    }

    public function test_eager_loading_uses_a_where_in_on_the_owner_key()
    {
        $this->fakeOData([
            $this->tableUrl('car') . '*' => Http::response($this->odataListResponse([
                ['model' => 'Celica', 'p_id' => 'P-1'],
                ['model' => 'Beetle', 'p_id' => 'P-2'],
            ])),
            $this->tableUrl('person') . '*' => Http::response($this->odataListResponse([
                ['id' => 'P-1', 'name_first' => 'David'],
                ['id' => 'P-2', 'name_first' => 'Steve'],
            ])),
        ]);

        $cars = Car::with('person')->where('model', '!=', '')->get();

        $this->assertTrue($cars[0]->relationLoaded('person'));
        $this->assertEquals('David', $cars[0]->person->name_first);
        $this->assertEquals('Steve', $cars[1]->person->name_first);

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('person'), 'get'));
        $this->assertEquals("id in ('P-1','P-2')", $params['$filter']);
    }
}
