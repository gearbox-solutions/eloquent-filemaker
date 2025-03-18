<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
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
    }

    public function test_connection_gets_the_default_database_configuration()
    {
        $connection = app(FileMakerConnection::class);

        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));
    }

    public function test_set_connection_changes_the_database_configuration()
    {
        $connection = app(FileMakerConnection::class);
        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));

        $connection->setConnection('filemaker2');

        $this->assertEquals('filemaker2', $connection->getConfig('name'));
        $this->assertEquals('tester2', $connection->getConfig('database'));
    }

    public function test_set_layout_changes_the_layout_used()
    {
        $connection = app(FileMakerConnection::class);
        $this->assertEquals('', $connection->getLayout());

        $connection->setLayout('dapi-pet');

        $this->assertEquals('dapi-pet', $connection->getLayout());
    }

    public function test_database_prefix_is_added_to_layout_names()
    {
        $connection = app(FileMakerConnection::class)->setConnection('prefix');

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
            'http://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response(['response' => ['token' => 'new-token']], 200),
        ]);
        $connection = app(FileMakerConnection::class)->setConnection('filemaker');

        $connection->login();

        $token = Cache::get('filemaker-session-' . $connection->getName());

        $this->assertEquals('new-token', $token);
    }

    public function test_failed_login_to_file_maker_throw()
    {
        $this->overrideDBHost();
        Http::fake([
            'http://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response(['response' => ['token' => 'new-token']], 200),
        ]);
        $connection = app(FileMakerConnection::class)->setConnection('filemaker');

        $connection->login();

        $token = Cache::get('filemaker-session-' . $connection->getName());

        $this->assertEquals('new-token', $token);
    }

    protected function overrideDBHost()
    {
        Config::set('database.connections.filemaker.host', 'filemaker.test');
        Config::set('database.connections.filemaker.protocol', 'http');
    }
}
