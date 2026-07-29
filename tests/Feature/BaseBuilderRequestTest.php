<?php

namespace Tests\Feature;

use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Illuminate\Http\File;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\MocksDataApi;
use Tests\TestCase;

/**
 * Tests that base query builder operations send the correct requests to the
 * FileMaker Data API and correctly process the responses.
 */
class BaseBuilderRequestTest extends TestCase
{
    use MocksDataApi;

    public function test_get_with_wheres_posts_a_find_request()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo', 'type' => 'cat']),
            ])),
        ]);

        $records = FM::layout('pet')->where('name', 'Cosmo')->get();

        $this->assertInstanceOf(Collection::class, $records);
        $this->assertCount(1, $records);
        $this->assertEquals('Cosmo', $records[0]['fieldData']['name']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/_find');
        $this->assertEquals('POST', $request->method());
        $this->assertEquals([['name' => 'Cosmo']], $request->data()['query']);
        // no limit specified, so a very high limit is sent to bypass FileMaker's default of 100
        $this->assertEquals(FileMakerConnection::CRAZY_RECORDS_AMOUNT, $request->data()['limit']);
    }

    public function test_get_without_wheres_fetches_the_records_endpoint()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
                $this->fmRecord(['name' => 'Fido'], [], '2'),
            ])),
        ]);

        $records = FM::layout('pet')->get();

        $this->assertCount(2, $records);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/', 'get');
        $params = $this->queryParams($request);
        $this->assertEquals(FileMakerConnection::CRAZY_RECORDS_AMOUNT, $params['_limit']);
        $this->assertArrayNotHasKey('_offset', $params);
    }

    public function test_limit_offset_and_sort_are_sent_as_query_parameters_when_getting_records()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([])),
        ]);

        FM::layout('pet')->limit(5)->offset(10)->orderBy('name')->get();

        $params = $this->queryParams($this->recordedRequest($this->layoutUrl('pet') . '/records/', 'get'));

        $this->assertEquals(5, $params['_limit']);
        // FileMaker offsets are 1-indexed starting records
        $this->assertEquals(11, $params['_offset']);
        $this->assertEquals(json_encode([['fieldName' => 'name', 'sortOrder' => 'ascend']]), $params['_sort']);
    }

    public function test_limit_offset_and_sort_are_sent_in_a_find_request_body()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        FM::layout('pet')->where('type', 'cat')->limit(5)->offset(10)->orderByDesc('name')->get();

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();

        $this->assertEquals([['type' => 'cat']], $data['query']);
        $this->assertEquals(5, $data['limit']);
        $this->assertEquals(11, $data['offset']);
        $this->assertEquals([['fieldName' => 'name', 'sortOrder' => 'descend']], $data['sort']);
    }

    public function test_first_limits_the_query_to_one_record()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
            ])),
        ]);

        $record = FM::layout('pet')->first();

        $this->assertEquals('Cosmo', $record['fieldData']['name']);

        $params = $this->queryParams($this->recordedRequest($this->layoutUrl('pet') . '/records/', 'get'));
        $this->assertEquals(1, $params['_limit']);
    }

    public function test_a_find_with_no_matching_records_returns_an_empty_collection()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmErrorResponse(401, 'No records match the request')),
        ]);

        $records = FM::layout('pet')->where('name', 'Nothing')->get();

        $this->assertInstanceOf(Collection::class, $records);
        $this->assertCount(0, $records);
    }

    public function test_count_returns_the_found_count()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
            ], 42)),
        ]);

        $count = FM::layout('pet')->where('type', 'cat')->count();

        $this->assertSame(42, $count);

        // count should only ask for a single record
        $this->assertEquals(1, $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data()['limit']);
    }

    public function test_paginate_returns_a_length_aware_paginator()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo']),
                $this->fmRecord(['name' => 'Fido'], [], '2'),
            ], 10)),
        ]);

        $paginator = FM::layout('pet')->where('type', 'cat')->paginate(2, ['*'], 'page', 3);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertEquals(10, $paginator->total());
        $this->assertEquals(3, $paginator->currentPage());
        $this->assertCount(2, $paginator->items());

        // page 3 at 2 per page starts at record 5 (1-indexed)
        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();
        $this->assertEquals(2, $data['limit']);
        $this->assertEquals(5, $data['offset']);
    }

    public function test_min_and_max_sort_and_read_the_first_record()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo', 'serial' => 5]),
            ])),
        ]);

        $min = FM::layout('pet')->min('serial');
        $this->assertEquals(5, $min);

        $params = $this->queryParams($this->recordedRequests($this->layoutUrl('pet') . '/records/', 'get')[0]);
        $this->assertEquals(json_encode([['fieldName' => 'serial', 'sortOrder' => 'ascend']]), $params['_sort']);

        $max = FM::layout('pet')->max('serial');
        $this->assertEquals(5, $max);

        $params = $this->queryParams($this->recordedRequests($this->layoutUrl('pet') . '/records/', 'get')[1]);
        $this->assertEquals(json_encode([['fieldName' => 'serial', 'sortOrder' => 'descend']]), $params['_sort']);
    }

    public function test_insert_posts_field_data_and_converts_nulls_to_empty_strings()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['recordId' => '100', 'modId' => '0'])),
        ]);

        $response = FM::layout('pet')->insert([
            'name' => 'Fido',
            'type' => null,
        ]);

        $this->assertEquals('100', $response['response']['recordId']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/', 'post');
        $this->assertEquals([
            'fieldData' => ['name' => 'Fido', 'type' => ''],
        ], $this->bodyData($request));
    }

    public function test_update_with_a_record_id_patches_the_record()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '2'])),
        ]);

        $updatedCount = FM::layout('pet')->recordId(55)->update(['name' => 'Updated Name']);

        $this->assertSame(1, $updatedCount);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/55', 'patch');
        $this->assertEquals(['fieldData' => ['name' => 'Updated Name']], $this->bodyData($request));
    }

    public function test_update_with_a_mod_id_includes_the_mod_id()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '4'])),
        ]);

        FM::layout('pet')->recordId(55)->modId('3')->update(['name' => 'Updated Name']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/55', 'patch');
        $this->assertEquals('3', $request->data()['modId']);
    }

    public function test_update_from_a_query_finds_records_and_patches_each_one()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo'], [], '7'),
                $this->fmRecord(['name' => 'Cosmo'], [], '8'),
            ])),
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '1'])),
        ]);

        $updatedCount = FM::layout('pet')->where('name', 'Cosmo')->update(['type' => 'cat']);

        $this->assertSame(2, $updatedCount);

        $patches = $this->recordedRequests($this->layoutUrl('pet') . '/records/', 'patch');
        $this->assertCount(2, $patches);
        $this->assertEquals($this->layoutUrl('pet') . '/records/7', $patches[0]->url());
        $this->assertEquals($this->layoutUrl('pet') . '/records/8', $patches[1]->url());
        $this->assertEquals(['fieldData' => ['type' => 'cat']], $this->bodyData($patches[0]));
    }

    public function test_update_from_a_query_with_no_matches_updates_nothing()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmErrorResponse(401, 'No records match the request')),
        ]);

        $updatedCount = FM::layout('pet')->where('name', 'Nothing')->update(['type' => 'cat']);

        $this->assertSame(0, $updatedCount);
        $this->assertCount(0, $this->recordedRequests($this->layoutUrl('pet') . '/records/', 'patch'));
    }

    public function test_delete_by_record_id_sends_a_delete_request()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse()),
        ]);

        $deletedCount = FM::layout('pet')->deleteByRecordId(66);

        $this->assertSame(1, $deletedCount);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/66', 'delete');
        $this->assertEquals('DELETE', $request->method());
    }

    public function test_delete_by_record_id_returns_zero_when_the_record_is_missing()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmErrorResponse(101, 'Record is missing')),
        ]);

        $deletedCount = FM::layout('pet')->deleteByRecordId(66);

        $this->assertSame(0, $deletedCount);
    }

    public function test_delete_with_a_query_bulk_deletes_the_found_records()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Delete Tester'], [], '7'),
                $this->fmRecord(['name' => 'Delete Tester'], [], '8'),
            ])),
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse()),
        ]);

        $deletedCount = FM::layout('pet')->where('name', 'Delete Tester')->delete();

        $this->assertSame(2, $deletedCount);

        $deletes = $this->recordedRequests($this->layoutUrl('pet') . '/records/', 'delete');
        $this->assertCount(2, $deletes);
        $this->assertEquals($this->layoutUrl('pet') . '/records/7', $deletes[0]->url());
        $this->assertEquals($this->layoutUrl('pet') . '/records/8', $deletes[1]->url());
    }

    public function test_duplicate_posts_to_the_record_url()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['recordId' => '99', 'modId' => '0'])),
        ]);

        $response = FM::layout('pet')->duplicate(42);

        $this->assertEquals('99', $response['response']['recordId']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/42', 'post');
        $this->assertEquals('POST', $request->method());
    }

    public function test_find_by_record_id_gets_a_single_record()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmRecordsResponse([
                $this->fmRecord(['name' => 'Cosmo'], [], '123'),
            ])),
        ]);

        $response = FM::layout('pet')->findByRecordId(123);

        $this->assertEquals('Cosmo', $response['response']['data'][0]['fieldData']['name']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/123', 'get');
        $this->assertEquals($this->layoutUrl('pet') . '/records/123', $request->url());
    }

    public function test_perform_script_executes_a_script_with_a_parameter()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/script/*' => Http::response($this->fmResultResponse(['scriptResult' => 'my param', 'scriptError' => '0'])),
        ]);

        $result = FM::layout('pet')->performScript('my script', 'my param');

        $this->assertEquals('my param', $result['response']['scriptResult']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/script/', 'get');
        $this->assertStringStartsWith($this->layoutUrl('pet') . '/script/my%20script', $request->url());
        $this->assertEquals(['script.param' => 'my param'], $this->queryParams($request));
    }

    public function test_a_script_can_be_set_in_chained_calls_before_executing()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/script/*' => Http::response($this->fmResultResponse(['scriptResult' => 'ok', 'scriptError' => '0'])),
        ]);

        FM::layout('pet')->script('my script')->scriptParam('chained param')->performScript();

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/script/', 'get');
        $this->assertEquals(['script.param' => 'chained param'], $this->queryParams($request));
    }

    public function test_script_options_are_included_in_a_find_request()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        FM::layout('pet')->where('name', 'Cosmo')
            ->script('after script', 'after param')
            ->scriptPresort('presort script', 'presort param')
            ->scriptPrerequest('prerequest script', 'prerequest param')
            ->layoutResponse('other layout')
            ->get();

        $data = $this->recordedRequest($this->layoutUrl('pet') . '/_find')->data();

        $this->assertEquals('after script', $data['script']);
        $this->assertEquals('after param', $data['script.param']);
        $this->assertEquals('presort script', $data['script.presort']);
        $this->assertEquals('presort param', $data['script.presort.param']);
        $this->assertEquals('prerequest script', $data['script.prerequest']);
        $this->assertEquals('prerequest param', $data['script.prerequest.param']);
        $this->assertEquals('other layout', $data['layout.response']);
    }

    public function test_portal_options_are_included_in_a_find_request()
    {
        $this->fakeDataApi([
            $this->layoutUrl('person') . '/_find' => Http::response($this->fmRecordsResponse([])),
        ]);

        FM::layout('person')->where('nameFirst', 'David')
            ->portal('cars')
            ->limitPortal('cars', 3)
            ->offsetPortal('cars', 2)
            ->get();

        $data = $this->recordedRequest($this->layoutUrl('person') . '/_find')->data();

        $this->assertEquals(['cars'], $data['portal']);
        $this->assertEquals(3, $data['limit.cars']);
        $this->assertEquals(2, $data['offset.cars']);
    }

    public function test_portal_limits_are_included_when_getting_records()
    {
        $this->fakeDataApi([
            $this->layoutUrl('person') . '/records/*' => Http::response($this->fmRecordsResponse([])),
        ]);

        FM::layout('person')->limitPortal('cars', 3)->offsetPortal('cars', 2)->get();

        $params = $this->queryParams($this->recordedRequest($this->layoutUrl('person') . '/records/', 'get'));

        $this->assertEquals(3, $params['_limit.cars']);
        $this->assertEquals(2, $params['_offset.cars']);
    }

    public function test_get_layout_metadata_fetches_the_layout_endpoint()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '*' => Http::response($this->fmResultResponse([
                'fieldMetaData' => [['name' => 'name'], ['name' => 'type']],
            ])),
        ]);

        $metadata = FM::getLayoutMetadata('pet');

        $this->assertCount(2, $metadata['response']['fieldMetaData']);

        $request = $this->recordedRequest($this->layoutUrl('pet'), 'get');
        $this->assertEquals($this->layoutUrl('pet'), $request->url());
    }

    public function test_get_layout_metadata_can_include_a_record_id()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '*' => Http::response($this->fmResultResponse([
                'fieldMetaData' => [['name' => 'name']],
            ])),
        ]);

        FM::layout('pet')->recordId(879)->getLayoutMetadata();

        $request = $this->recordedRequest($this->layoutUrl('pet'), 'get');
        $this->assertEquals(['recordId' => '879'], $this->queryParams($request));
    }

    public function test_set_container_uploads_a_file_to_the_container_field()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '2'])),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        FM::layout('pet')->recordId(12)->setContainer('photo', new File($path));

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/12/containers/photo', 'post');
        $this->assertTrue($request->hasFile('upload'));

        unlink($path);
    }

    public function test_set_container_can_upload_with_a_custom_file_name()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '2'])),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        FM::layout('pet')->recordId(12)->setContainer('photo', [new File($path), 'fluffy.jpg']);

        $request = $this->recordedRequest($this->layoutUrl('pet') . '/records/12/containers/photo', 'post');
        $this->assertTrue($request->hasFile('upload', null, 'fluffy.jpg'));

        unlink($path);
    }

    public function test_updating_a_container_and_field_data_together_uploads_then_patches()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/records/12/containers/*' => Http::response($this->fmResultResponse(['modId' => '7'])),
            $this->layoutUrl('pet') . '/records/*' => Http::response($this->fmResultResponse(['modId' => '8'])),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        $updatedCount = FM::layout('pet')->recordId(12)->update([
            'name' => 'Fluffy',
            'photo' => new File($path),
        ]);

        $this->assertSame(1, $updatedCount);

        $upload = $this->recordedRequest($this->layoutUrl('pet') . '/records/12/containers/photo', 'post');
        $this->assertTrue($upload->hasFile('upload'));

        // the container upload bumps the modId, which should be sent with the field data patch
        $patch = $this->recordedRequest($this->layoutUrl('pet') . '/records/12', 'patch');
        $this->assertEquals(['fieldData' => ['name' => 'Fluffy'], 'modId' => '7'], $this->bodyData($patch));

        unlink($path);
    }
}
