<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
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

    public function test_layout_name_is_encoded_in_url()
    {
        $connection = $this->connection();
        $connection->setLayout('my layout');

        // Use reflection to call the protected getLayoutUrl
        $ref = new \ReflectionMethod($connection, 'getLayoutUrl');
        $url = $ref->invoke($connection);

        $this->assertStringContainsString('/layouts/my%20layout', $url);
        $this->assertStringNotContainsString('/layouts/my layout', $url);
    }

    public function test_database_name_is_encoded_in_url()
    {
        Config::set('database.connections.filemaker.database', 'my database');
        $connection = $this->connection();

        $ref = new \ReflectionMethod($connection, 'getDatabaseUrl');
        $url = $ref->invoke($connection);

        $this->assertStringContainsString('/databases/my%20database', $url);
    }

    public function test_invalid_record_id_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $builder = new FMBaseBuilder($this->connection());
        $builder->recordId('abc/../../hack');
    }

    public function test_numeric_record_id_is_accepted()
    {
        $builder = new FMBaseBuilder($this->connection());
        $builder->recordId(42);

        $this->assertEquals(42, $builder->getRecordId());
    }

    public function test_http_protocol_triggers_warning()
    {
        $triggered = false;
        set_error_handler(function ($errno, $errstr) use (&$triggered) {
            if (str_contains($errstr, 'uses plain HTTP')) {
                $triggered = true;
            }

            return true;
        });

        try {
            new FileMakerConnection('filemaker', 'tester', '', [
                'name' => 'filemaker',
                'host' => 'filemaker.test',
                'database' => 'tester',
                'username' => 'test',
                'password' => 'test',
                'protocol' => 'http',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($triggered, 'Expected a warning about plain HTTP');
    }

    public function test_http_protocol_with_opt_in_does_not_warn()
    {
        // Should not trigger a warning
        $connection = new FileMakerConnection('filemaker', 'tester', '', [
            'name' => 'filemaker',
            'host' => 'filemaker.test',
            'database' => 'tester',
            'username' => 'test',
            'password' => 'test',
            'protocol' => 'http',
            'allow_insecure_http' => true,
        ]);

        $this->assertInstanceOf(FileMakerConnection::class, $connection);
    }

    public function test_verify_ssl_false_disables_verification()
    {
        $connection = new FileMakerConnection('filemaker', 'tester', '', [
            'name' => 'filemaker',
            'host' => 'filemaker.test',
            'database' => 'tester',
            'username' => 'test',
            'password' => 'test',
            'protocol' => 'https',
            'verify_ssl' => false,
            'cache_session_token' => false,
        ]);

        $ref = new \ReflectionMethod($connection, 'prepareRequestForSending');
        $request = $ref->invoke($connection);

        // The pending request options should have verify=false
        $options = $request->getOptions();
        $this->assertFalse($options['verify']);
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
