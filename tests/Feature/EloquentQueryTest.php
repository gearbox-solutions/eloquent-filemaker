<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Tests\Models\Car;
use Tests\Models\Person;
use Tests\Models\Pet;
use Tests\Support\MocksDataApi;
use Tests\TestCase;

class EloquentQueryTest extends TestCase
{
    use MocksDataApi;

    public function test_a_model_query_sends_a_find_request_and_hydrates_models()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['id' => 'ABC-123', 'name' => 'Cosmo', 'type' => 'cat'], [], '879', '2'),
            ])),
        ]);

        $pets = Pet::where('petName', 'Cosmo')->get();

        $this->assertInstanceOf(Collection::class, $pets);
        $this->assertInstanceOf(Pet::class, $pets[0]);
        $this->assertEquals('Cosmo', $pets[0]->petName);
        $this->assertEquals('879', $pets[0]->getRecordId());
        $this->assertTrue($pets[0]->exists);

        // the query should use the FileMaker field name and include the global scope
        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals([['name' => 'Cosmo', 'flagged' => '1']], $data['query']);
    }

    public function test_global_scopes_are_applied_to_queries_without_wheres()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        Pet::query()->get();

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals([['flagged' => '1']], $data['query']);
    }

    public function test_without_global_scopes_gets_all_records()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([])),
        ]);

        Pet::withoutGlobalScopes()->get();

        // with no query parameters at all this is a get records request instead of a find
        $this->recordedRequest($this->layoutUrl('pet') . '/records/', 'get');
    }

    public function test_global_scopes_are_applied_to_each_find_request()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        Pet::where('petName', 'Cosmo')->orWhere('petName', 'Astrid')->get();

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals([
            ['name' => 'Cosmo', 'flagged' => '1'],
            ['name' => 'Astrid', 'flagged' => '1'],
        ], $data['query']);
    }

    public function test_local_scopes_combine_with_wheres_and_where_ins()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        Pet::withoutGlobalScopes()
            ->where('flagged', 1)
            ->cats()
            ->whereIn('petName', ['Cosmo', 'Astrid', 'Scooter'])
            ->get();

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals([
            ['flagged' => '1', 'type' => 'cat', 'name' => 'Cosmo'],
            ['flagged' => '1', 'type' => 'cat', 'name' => 'Astrid'],
            ['flagged' => '1', 'type' => 'cat', 'name' => 'Scooter'],
        ], $data['query']);
    }

    public function test_or_where_in_after_a_scope_adds_separate_find_requests()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        Pet::withoutGlobalScopes()
            ->where('flagged', 1)
            ->cats()
            ->orWhereIn('petName', ['Cosmo', 'Astrid'])
            ->get();

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals([
            ['flagged' => '1', 'type' => 'cat'],
            ['name' => 'Cosmo'],
            ['name' => 'Astrid'],
        ], $data['query']);
    }

    public function test_find_queries_by_the_primary_key()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['id' => 'ABC-123', 'name' => 'Cosmo']),
            ])),
        ]);

        $pet = Pet::find('ABC-123');

        $this->assertEquals('ABC-123', $pet->id);

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals([['id' => '=ABC-123', 'flagged' => '1']], $data['query']);
    }

    public function test_find_or_fail_throws_when_no_records_match()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmErrorResponse(401, 'No records match the request')),
        ]);

        $this->expectException(ModelNotFoundException::class);

        Pet::findOrFail('nothing');
    }

    public function test_first_limits_the_query_to_a_single_record()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
            ])),
        ]);

        $pet = Pet::where('petName', 'Cosmo')->first();

        $this->assertInstanceOf(Pet::class, $pet);

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals(1, $data['limit']);
    }

    public function test_value_returns_a_single_attribute_from_the_first_record()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo', 'type' => 'cat']),
            ])),
        ]);

        $this->assertEquals('cat', Pet::where('petName', 'Cosmo')->value('type'));
    }

    public function test_count_returns_the_found_count()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
            ], 25)),
        ]);

        $this->assertSame(25, Pet::query()->count());
    }

    public function test_paginate_returns_a_paginator_of_models()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
                $this->fmRecord(['name' => 'Astrid'], [], '2'),
            ], 12)),
        ]);

        $paginator = Pet::paginate(2);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertEquals(12, $paginator->total());
        $this->assertCount(2, $paginator->items());
        $this->assertInstanceOf(Pet::class, $paginator->items()[0]);
        $this->assertEquals('Cosmo', $paginator->items()[0]->petName);
    }

    public function test_has_many_queries_the_related_layout_by_foreign_key()
    {
        $this->fakeDataApi([
            $this->layoutUrl('car') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['model' => 'Celica', 'person_id' => 'P-1'], [], '10'),
                $this->fmRecord(['model' => 'Supra', 'person_id' => 'P-1'], [], '11'),
            ])),
        ]);

        $person = Person::createFromRecord($this->fmRecord(['id' => 'P-1', 'nameFirst' => 'David']));

        $cars = $person->cars;

        $this->assertCount(2, $cars);
        $this->assertInstanceOf(Car::class, $cars[0]);
        $this->assertEquals('Celica', $cars[0]->model);

        $data = $this->recordedRequest($this->layoutUrl('car') . '/_find')->data();
        $this->assertEquals([['person_id' => '==P-1']], $data['query']);
    }

    public function test_belongs_to_queries_the_owner_by_its_primary_key()
    {
        $this->fakeDataApi([
            $this->layoutUrl('person') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['id' => 'P-1', 'nameFirst' => 'David']),
            ])),
        ]);

        $car = Car::createFromRecord($this->fmRecord(['model' => 'Celica', 'person_id' => 'P-1']));

        $person = $car->person;

        $this->assertInstanceOf(Person::class, $person);
        $this->assertEquals('David', $person->name_first);

        // Person maps the 'id' FileMaker field to the primaryKey attribute
        $data = $this->recordedRequest($this->layoutUrl('person') . '/_find')->data();
        $this->assertEquals([['id' => '==P-1']], $data['query']);
    }

    public function test_eager_loading_uses_a_where_in_on_the_owner_key()
    {
        $this->fakeDataApi([
            $this->layoutUrl('car') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['model' => 'Celica', 'person_id' => 'P-1'], [], '10'),
                $this->fmRecord(['model' => 'Beetle', 'person_id' => 'P-2'], [], '11'),
            ])),
            $this->layoutUrl('person') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['id' => 'P-1', 'nameFirst' => 'David'], [], '20'),
                $this->fmRecord(['id' => 'P-2', 'nameFirst' => 'Steve'], [], '21'),
            ])),
        ]);

        $cars = Car::with('person')->where('model', '*')->get();

        $this->assertTrue($cars[0]->relationLoaded('person'));
        $this->assertEquals('David', $cars[0]->person->name_first);
        $this->assertEquals('Steve', $cars[1]->person->name_first);

        $data = $this->recordedRequest($this->layoutUrl('person') . '/_find')->data();
        $this->assertEquals([['id' => 'P-1'], ['id' => 'P-2']], $data['query']);
    }
}
