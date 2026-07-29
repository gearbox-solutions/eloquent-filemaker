<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerODataException;
use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MocksOData;
use Tests\TestCase;

class FileMakerConnectionTest extends TestCase
{
    use MocksOData;

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

    public function test_table_returns_a_base_builder_for_the_table()
    {
        $builder = DB::connection('filemaker')->table('pet');

        $this->assertInstanceOf(FMBaseBuilder::class, $builder);
        $this->assertEquals('pet', $builder->from);
    }

    public function test_requests_are_sent_with_basic_auth()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => Http::response($this->odataListResponse([])),
        ]);

        FM::table('pet')->get();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('odatatester:odatatester'));
        });
    }

    public function test_database_prefix_is_added_to_table_names()
    {
        $this->fakeOData([
            $this->baseUrl('prefix') . '/odata-pet*' => Http::response($this->odataListResponse([])),
        ]);

        DB::connection('prefix')->table('pet')->get();

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), $this->baseUrl('prefix') . '/odata-pet');
        });
    }

    public function test_an_error_response_throws_an_odata_exception_with_the_http_status_as_the_code()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => $this->odataErrorResponse('Invalid credentials', 401),
        ]);

        $this->expectException(FileMakerODataException::class);
        $this->expectExceptionCode(401);

        FM::table('pet')->where('name', 'Cosmo')->get();
    }

    public function test_error_message_is_taken_from_the_odata_error_body()
    {
        $this->fakeOData([
            $this->tableUrl('pet') . '*' => $this->odataErrorResponse('Field is missing', 400),
        ]);

        try {
            FM::table('pet')->get();
            $this->fail('A FileMakerODataException should have been thrown');
        } catch (FileMakerODataException $e) {
            $this->assertEquals('Field is missing', $e->getMessage());
            $this->assertEquals(400, $e->getCode());
        }
    }
}
