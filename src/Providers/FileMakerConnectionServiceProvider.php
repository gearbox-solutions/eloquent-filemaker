<?php

namespace BlueFeather\EloquentFileMaker\Providers;

use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Support\ServiceProvider;

class FileMakerConnectionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {

        // register the FM facade
        $this->app->bind('fm', function ($app) {
            return $app['db']->connection();
        });
        $this->app->bind(FileMakerConnection::class, function ($app) {
            return $app['fm'];
        });

        // extend DatabaseResolver to handle 'filemaker' as a driver and return a FileMakerConnection
        $this->app->resolving('db', function ($db) {
            $db->extend('filemaker', function ($config, $connectionName) {
                return new FileMakerConnection($connectionName, $config['database'], $config['prefix'], $config);
            });
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
