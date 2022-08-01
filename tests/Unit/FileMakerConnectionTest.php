<?php

namespace Tests\Unit;

use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
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

    public function testConnectionGetsTheDefaultDatabaseConfiguration()
    {
        $connection = app(FileMakerConnection::class);

        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));
    }

    public function testSetConnectionChangesTheDatabaseConfiguration()
    {
        $connection = app(FileMakerConnection::class);
        $this->assertEquals('filemaker', $connection->getConfig('name'));
        $this->assertEquals('tester', $connection->getConfig('database'));

        $connection->setConnection('filemaker2');

        $this->assertEquals('filemaker2', $connection->getConfig('name'));
        $this->assertEquals('tester2', $connection->getConfig('database'));
    }

    public function testSetLayoutChangesTheLayoutUsed()
    {
        $connection = app(FileMakerConnection::class);
        $this->assertEquals('', $connection->getLayout());

        $connection->setLayout('dapi-pet');

        $this->assertEquals('dapi-pet', $connection->getLayout());
    }

    public function testDatabasePrefixIsAddedToLayoutNames()
    {
        $connection = app(FileMakerConnection::class)->setConnection('prefix');

        $this->assertEquals('dapi-', $connection->getLayout());

        $connection->setLayout('pet');
        $this->assertEquals('dapi-pet', $connection->getLayout());

        $connection->setLayout('car');
        $this->assertEquals('dapi-car', $connection->getLayout());
    }

    public function testLoginToFileMaker()
    {
        $this->overrideDBHost();
        Http::fake([
            'http://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response(['response' => ['token' => 'new-token']], 200),
        ]);
        $connection = app(FileMakerConnection::class)->setConnection('filemaker');

        $connection->login();

        $token = Cache::get('filemaker-session-'.$connection->getName());

        $this->assertEquals('new-token', $token);
    }

    public function testFailedLoginToFileMakerThrow()
    {
        $this->overrideDBHost();
        Http::fake([
            'http://filemaker.test/fmi/data/vLatest/databases/tester/sessions' => Http::response(['response' => ['token' => 'new-token']], 200),
        ]);
        $connection = app(FileMakerConnection::class)->setConnection('filemaker');

        $connection->login();

        $token = Cache::get('filemaker-session-'.$connection->getName());

        $this->assertEquals('new-token', $token);
    }

    protected function overrideDBHost()
    {
        Config::set('database.connections.filemaker.host', 'filemaker.test');
        Config::set('database.connections.filemaker.protocol', 'http');
    }
}
