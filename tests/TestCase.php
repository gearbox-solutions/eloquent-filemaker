<?php

namespace Tests;

use BlueFeather\EloquentFileMaker\Support\Facades\FM;
use BlueFeather\EloquentFileMaker\Providers\FileMakerConnectionServiceProvider;
use Orchestra\Testbench\TestCase as OchestraTestCase;

class TestCase extends OchestraTestCase
{
    protected $loadEnvironmentVariables = true;

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            FileMakerConnectionServiceProvider::class,
        ];
    }

    /**
     * Override application aliases.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function overrideApplicationProviders($app)
    {
        return [
            'FM' => FM::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $config = require 'config/database.php';

        $app['config']->set('app.key', 'iLhkC64h3FsbnfBbb1Z3KrH06WKsQw7w');

        $app['config']->set('database.default', 'filemaker');
        $app['config']->set('database.connections.filemaker', $config['connections']['filemaker']);
        $app['config']->set('database.connections.filemaker2', $config['connections']['filemaker2']);
        $app['config']->set('database.connections.prefix', $config['connections']['prefix']);

        $app['config']->set('auth.model', 'User');
        $app['config']->set('auth.providers.users.model', 'User');
    }
}
