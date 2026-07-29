<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MocksDataApi;
use Tests\TestCase;

class FileMakerConnectionTest extends TestCase
{
    use MocksDataApi;

    public function test_connection_gets_the_default_database_configuration()
    {
        $connection = app(FileMakerConnection::class);

        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));
    }

    public function test_each_connection_gets_its_own_configuration()
    {
        $connection = DB::connection('filemaker2');

        $this->assertEquals('filemaker2', $connection->getConfig('name'));
        $this->assertEquals('tester2', $connection->getConfig('database'));
    }

    public function test_set_layout_changes_the_layout_used()
    {
        $connection = app(FileMakerConnection::class);

        $connection->setLayout('dapi-pet');

        $this->assertEquals('dapi-pet', $connection->getLayout());
    }

    public function test_database_prefix_is_added_to_layout_names()
    {
        $connection = DB::connection('prefix');

        $connection->setLayout('pet');
        $this->assertEquals('dapi-pet', $connection->getLayout());

        $connection->setLayout('car');
        $this->assertEquals('dapi-car', $connection->getLayout());
    }

    public function test_table_returns_a_base_builder_for_the_layout()
    {
        $builder = DB::connection('filemaker')->layout('pet');

        $this->assertInstanceOf(FMBaseBuilder::class, $builder);
        $this->assertEquals('pet', $builder->from);
    }

    public function test_login_fetches_and_caches_a_session_token()
    {
        $this->fakeDataApi();

        $connection = DB::connection('filemaker');
        $connection->login();

        $this->assertEquals('test-session-token', Cache::get('eloquent-filemaker-session-token-filemaker'));

        Http::assertSent(function ($request) {
            return $request->url() === $this->baseUrl() . '/sessions'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('dapitester:dapitester'));
        });
    }

    public function test_login_reuses_a_cached_session_token()
    {
        Http::fake();
        Cache::forever('eloquent-filemaker-session-token-filemaker', 'already-cached-token');

        DB::connection('filemaker')->login();

        Http::assertNothingSent();
    }

    public function test_session_token_is_not_cached_when_caching_is_disabled()
    {
        Config::set('database.connections.filemaker.cache_session_token', false);
        DB::purge('filemaker');

        $this->fakeDataApi();

        DB::connection('filemaker')->login();

        $this->assertNull(Cache::get('eloquent-filemaker-session-token-filemaker'));
        Http::assertSentCount(1);
    }

    public function test_a_failed_login_throws_a_data_api_exception()
    {
        Http::fake([
            $this->baseUrl() . '/sessions' => Http::response($this->fmErrorResponse(212, 'Invalid user account and/or password')),
        ]);

        $this->expectException(FileMakerDataApiException::class);
        $this->expectExceptionCode(212);

        FM::layout('pet')->where('name', 'Cosmo')->get();
    }

    public function test_disconnect_ends_the_session_and_forgets_the_token()
    {
        $this->fakeDataApi([
            $this->baseUrl() . '/sessions/*' => Http::response($this->fmResultResponse()),
        ]);

        $connection = DB::connection('filemaker');
        $connection->login();
        $this->assertNotNull(Cache::get('eloquent-filemaker-session-token-filemaker'));

        $connection->disconnect();

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === $this->baseUrl() . '/sessions/test-session-token';
        });
        $this->assertNull(Cache::get('eloquent-filemaker-session-token-filemaker'));
    }

    public function test_an_expired_session_token_is_refreshed_and_the_request_retried()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::sequence()
                ->push($this->fmErrorResponse(952, 'Invalid FileMaker Data API token'))
                ->push($this->fmRecordsResponse([
                    $this->fmRecord(['name' => 'Cosmo']),
                ])),
        ]);

        $records = FM::layout('pet')->where('name', 'Cosmo')->get();

        $this->assertCount(1, $records);

        // the expired token should have triggered a second login and a second find request
        $this->assertCount(2, $this->recordedRequests($this->baseUrl() . '/sessions', 'post'));
        $this->assertCount(2, $this->recordedRequests($this->layoutUrl('pet') . '/_find'));
    }

    public function test_data_api_errors_throw_an_exception_with_the_layout_and_code()
    {
        $this->fakeDataApi([
            $this->layoutUrl('pet') . '/_find' => Http::response($this->fmErrorResponse(102, 'Field is missing')),
        ]);

        try {
            FM::layout('pet')->where('name', 'Cosmo')->get();
            $this->fail('A FileMakerDataApiException should have been thrown');
        } catch (FileMakerDataApiException $e) {
            $this->assertEquals(102, $e->getCode());
            $this->assertEquals('Layout: pet - Field is missing', $e->getMessage());
        }
    }

    public function test_set_global_fields_patches_the_globals_endpoint()
    {
        $this->fakeDataApi([
            $this->baseUrl() . '/globals/' => Http::response($this->fmResultResponse()),
        ]);

        FM::setGlobalFields(['GLOB::testGlobal' => 'a test global value']);

        $request = $this->recordedRequest($this->baseUrl() . '/globals/', 'patch');
        $this->assertEquals([
            'globalFields' => ['GLOB::testGlobal' => 'a test global value'],
        ], $request->data());
    }
}
