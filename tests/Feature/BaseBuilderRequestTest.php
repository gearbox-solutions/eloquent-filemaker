<?php

namespace Tests\Feature;

use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerODataException;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Illuminate\Http\File;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\MocksOData;
use Tests\TestCase;

/**
 * Tests that base query builder operations send the correct requests to FileMaker's
 * OData API and correctly process the responses.
 */
class BaseBuilderRequestTest extends TestCase
{
    use MocksOData;

    public function test_get_with_a_where_sends_a_filter_query_param()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo', 'type' => 'cat'],
            ])),
        ]);

        $records = FM::table('pet')->where('name', 'Cosmo')->get();

        $this->assertInstanceOf(Collection::class, $records);
        $this->assertCount(1, $records);
        $this->assertEquals('Cosmo', $records[0]['name']);

        $request = $this->recordedRequest($this->tableUrl('pet'), 'get');
        $params = $this->queryParams($request);
        $this->assertEquals("name eq 'Cosmo'", $params['$filter']);
    }

    public function test_get_without_wheres_sends_no_filter_param()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo'],
                ['name' => 'Fido'],
            ])),
        ]);

        $records = FM::table('pet')->get();

        $this->assertCount(2, $records);

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertArrayNotHasKey('$filter', $params);
    }

    public function test_limit_offset_and_sort_are_sent_as_odata_query_options()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        FM::table('pet')->limit(5)->offset(10)->orderBy('name')->get();

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));

        $this->assertEquals('5', $params['$top']);
        $this->assertEquals('10', $params['$skip']);
        $this->assertEquals('name asc', $params['$orderby']);
    }

    public function test_get_can_filter_each_record_down_to_specific_keys()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['id' => '10', 'name' => 'Cosmo', 'type' => 'cat'],
                ['id' => '11', 'name' => 'Fido', 'type' => 'dog'],
            ])),
        ]);

        $records = FM::table('pet')->get(['name']);

        $this->assertEquals(['name' => 'Cosmo'], $records[0]);
        $this->assertEquals(['name' => 'Fido'], $records[1]);
    }

    public function test_first_limits_the_query_to_one_record()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo'],
            ])),
        ]);

        $record = FM::table('pet')->first();

        $this->assertEquals('Cosmo', $record['name']);

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals('1', $params['$top']);
    }

    public function test_a_query_with_no_matching_records_returns_an_empty_collection()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        $records = FM::table('pet')->where('name', 'Nothing')->get();

        $this->assertInstanceOf(Collection::class, $records);
        $this->assertCount(0, $records);
    }

    public function test_count_uses_the_dollar_count_path_segment()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(42),
        ]);

        $count = FM::table('pet')->where('type', 'cat')->count();

        $this->assertSame(42, $count);

        $request = $this->recordedRequest($this->tableUrl('pet') . '/$count', 'get');
        $this->assertEquals("type eq 'cat'", $this->queryParams($request)['$filter']);
    }

    public function test_paginate_uses_dollar_count_true_to_get_the_total()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo'],
                ['name' => 'Fido'],
            ], 10)),
        ]);

        $paginator = FM::table('pet')->where('type', 'cat')->paginate(2, ['*'], 'page', 3);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertEquals(10, $paginator->total());
        $this->assertEquals(3, $paginator->currentPage());
        $this->assertCount(2, $paginator->items());

        $params = $this->queryParams($this->recordedRequest($this->tableUrl('pet'), 'get'));
        $this->assertEquals('2', $params['$top']);
        // page 3 at 2 per page skips the first 4 records
        $this->assertEquals('4', $params['$skip']);
        $this->assertEquals('true', $params['$count']);
    }

    public function test_min_and_max_sort_and_read_the_first_record()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([
                ['name' => 'Cosmo', 'serial' => 5],
            ])),
        ]);

        $min = FM::table('pet')->min('serial');
        $this->assertEquals(5, $min);

        $params = $this->queryParams($this->recordedRequests($this->tableUrl('pet'), 'get')[0]);
        $this->assertEquals('serial asc', $params['$orderby']);

        $max = FM::table('pet')->max('serial');
        $this->assertEquals(5, $max);

        $params = $this->queryParams($this->recordedRequests($this->tableUrl('pet'), 'get')[1]);
        $this->assertEquals('serial desc', $params['$orderby']);
    }

    public function test_insert_posts_flat_field_data_and_converts_nulls_to_empty_strings()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response(['id' => '100', 'name' => 'Fido', 'type' => '']),
        ]);

        $response = FM::table('pet')->insert([
            'name' => 'Fido',
            'type' => null,
        ]);

        $this->assertEquals('100', $response['id']);

        $request = $this->recordedRequest($this->tableUrl('pet'), 'post');
        $this->assertEquals(['name' => 'Fido', 'type' => ''], $this->bodyData($request));
    }

    public function test_update_counts_matching_records_then_patches_them_in_bulk()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(2),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $updatedCount = FM::table('pet')->where('name', 'Cosmo')->update(['type' => 'cat']);

        $this->assertSame(2, $updatedCount);

        $patch = $this->recordedRequest($this->tableUrl('pet'), 'patch');
        $this->assertEquals(['type' => 'cat'], $this->bodyData($patch));
        $this->assertEquals("name eq 'Cosmo'", $this->queryParams($patch)['$filter']);
    }

    public function test_update_with_no_matches_sends_no_patch_request()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(0),
        ]);

        $updatedCount = FM::table('pet')->where('name', 'Nothing')->update(['type' => 'cat']);

        $this->assertSame(0, $updatedCount);
        $this->assertCount(0, $this->recordedRequests($this->tableUrl('pet'), 'patch'));
    }

    public function test_delete_counts_matching_records_then_deletes_them_in_bulk()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(2),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $deletedCount = FM::table('pet')->where('name', 'Delete Tester')->delete();

        $this->assertSame(2, $deletedCount);

        $delete = $this->recordedRequest($this->tableUrl('pet'), 'delete');
        $this->assertEquals("name eq 'Delete Tester'", $this->queryParams($delete)['$filter']);
    }

    public function test_delete_by_id_shorthand_filters_on_the_id_column()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(1),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $deletedCount = FM::table('pet')->delete(66);

        $this->assertSame(1, $deletedCount);

        $delete = $this->recordedRequest($this->tableUrl('pet'), 'delete');
        $this->assertEquals('id eq 66', $this->queryParams($delete)['$filter']);
    }

    public function test_delete_with_no_matches_sends_no_delete_request()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(0),
        ]);

        $deletedCount = FM::table('pet')->where('name', 'Nothing')->delete();

        $this->assertSame(0, $deletedCount);
        $this->assertCount(0, $this->recordedRequests($this->tableUrl('pet'), 'delete'));
    }

    public function test_server_driven_paging_is_followed_via_the_next_link()
    {
        $nextLink = $this->tableUrl('pet') . '?$skiptoken=abc';

        Http::fakeSequence()
            ->push($this->odataListResponse([['name' => 'Cosmo']]) + ['@odata.nextLink' => $nextLink])
            ->push($this->odataListResponse([['name' => 'Fido']]));

        $records = FM::table('pet')->get();

        $this->assertEquals(['Cosmo', 'Fido'], $records->pluck('name')->all());

        // the next link is a complete URL and must be requested verbatim, not rebuilt
        $requests = $this->recordedRequests($this->tableUrl('pet'), 'get');
        $this->assertCount(2, $requests);
        $this->assertEquals($nextLink, $requests[1]->url());
    }

    public function test_a_next_link_the_server_repeats_does_not_loop_forever()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response(
                $this->odataListResponse([['name' => 'Cosmo']]) + ['@odata.nextLink' => $this->tableUrl('pet') . '?$skiptoken=abc']
            ),
        ]);

        $records = FM::table('pet')->get();

        $this->assertCount(2, $records);
        $this->assertCount(2, $this->recordedRequests($this->tableUrl('pet'), 'get'));
    }

    public function test_a_limit_cannot_be_applied_to_a_delete()
    {
        Http::fake();

        $this->expectException(FileMakerODataException::class);
        $this->expectExceptionMessage('A limit or offset cannot be applied to an OData delete');

        FM::table('pet')->where('type', 'cat')->limit(5)->delete();
    }

    public function test_a_limit_cannot_be_applied_to_an_update()
    {
        Http::fake();

        $this->expectException(FileMakerODataException::class);
        $this->expectExceptionMessage('A limit or offset cannot be applied to an OData update');

        FM::table('pet')->where('type', 'cat')->limit(5)->update(['name' => 'Cosmo']);
    }

    public function test_a_rejected_write_sends_no_requests_at_all()
    {
        Http::fake();

        try {
            FM::table('pet')->where('type', 'cat')->limit(5)->delete();
        } catch (FileMakerODataException) {
            // expected - assert below that nothing was written or even counted
        }

        Http::assertNothingSent();
    }

    public function test_container_fields_are_base64_encoded_inline_with_other_field_data()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '/$count*' => $this->odataCountResponse(1),
            $this->tableUrl('pet') . '*' => Http::response([]),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'efm-test-');
        file_put_contents($path, 'fake image data');

        FM::table('pet')->where('id', 12)->update([
            'name' => 'Fluffy',
            'photo' => new File($path),
        ]);

        $patch = $this->recordedRequest($this->tableUrl('pet'), 'patch');
        $this->assertEquals([
            'name' => 'Fluffy',
            'photo' => base64_encode('fake image data'),
        ], $this->bodyData($patch));

        unlink($path);
    }
}
