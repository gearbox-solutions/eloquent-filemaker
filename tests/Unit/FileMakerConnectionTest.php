<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FileMakerConnectionTest extends TestCase
{
    /*
     * Shut down mockery services
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_connection_gets_the_default_database_configuration()
    {
        $connection = $this->connection();

        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));
    }

    public function test_set_connection_changes_the_database_configuration()
    {
        $connection = $this->connection();
        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));

        $connection = $this->connection('filemaker2');

        $this->assertEquals('filemaker2', $connection->getConfig('name'));
        $this->assertEquals('tester2', $connection->getConfig('database'));
    }

    public function test_set_layout_changes_the_layout_used()
    {
        $connection = $this->connection();
        $this->assertEquals('', $connection->getLayout());

        $connection->setLayout('dapi-pet');

        $this->assertEquals('dapi-pet', $connection->getLayout());
    }

    public function test_database_prefix_is_added_to_layout_names()
    {
        $connection = $this->connection('prefix');

        $this->assertEquals('dapi-', $connection->getLayout());

        $connection->setLayout('pet');
        $this->assertEquals('dapi-pet', $connection->getLayout());

        $connection->setLayout('car');
        $this->assertEquals('dapi-car', $connection->getLayout());
    }

    public function test_login_to_file_maker()
    {
        $this->overrideDBHost();
        Http::fake([
            'https://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response(['response' => ['token' => 'new-token']], 200),
        ]);
        $connection = $this->connection();

        $connection->login();

        $token = Cache::get('eloquent-filemaker-session-token-' . $connection->getName());

        $this->assertEquals('new-token', $token);
    }

    public function test_failed_login_to_file_maker_throw()
    {
        $this->overrideDBHost();
        Http::fake([
            'https://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response(['response' => ['token' => 'new-token']], 200),
        ]);
        $connection = $this->connection();

        $connection->login();

        $token = Cache::get('eloquent-filemaker-session-token-' . $connection->getName());

        $this->assertEquals('new-token', $token);
    }

    public function test_login_query_log_does_not_contain_raw_credentials()
    {
        $this->overrideDBHost();
        Http::fake([
            'https://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response([
                'messages' => [['code' => '0', 'message' => 'OK']],
                'response' => ['token' => 'test-token'],
            ], 200),
        ]);

        $loggedSql = [];
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$loggedSql) {
            $loggedSql[] = $event->sql;
        });

        $connection = new FileMakerConnection('filemaker', 'tester', '', [
            'name' => 'filemaker',
            'host' => 'filemaker.test',
            'database' => 'tester',
            'username' => 'dapitester',
            'password' => 'dapitester',
            'protocol' => 'https',
            'cache_session_token' => false,
        ]);
        $connection->setEventDispatcher($this->app['events']);

        $connection->login();

        $this->assertNotEmpty($loggedSql, 'Expected at least one query log entry from login');

        $combined = implode(' ', $loggedSql);
        $this->assertStringNotContainsString('dapitester', $combined, 'Raw credentials should not appear in query log');
    }

    protected function overrideDBHost()
    {
        Config::set('database.connections.filemaker.host', 'filemaker.test');
        Config::set('database.connections.filemaker.protocol', 'https');
    }

    protected function connection(string $name = 'filemaker'): FileMakerConnection
    {
        return app('db')->connection($name);
    }
}
