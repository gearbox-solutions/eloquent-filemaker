<?php

namespace BlueFeather\EloquentFileMaker\Providers;

use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Database\DatabaseManager;
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

        // extend DatabaseResolver to handle 'filemaker' as a driver and return a FileMakerConnection
        $this->app->resolving('db', function ($db) {
            $db->extend('filemaker', function ($config, $connectionName) {
                return new FileMakerConnection($connectionName, $config['database'], $config['prefix'], $config);
            });
        });


        // register the FM facade
        $this->app->singleton('fm', function ($app) {
            return $app['db'];
        });
        $this->app->bind('fm.connection', function ($app) {
            return $app['fm']->connection();
        });
        $this->app->bind(FileMakerConnection::class, function ($app) {
            return $app['fm.connection'];
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
