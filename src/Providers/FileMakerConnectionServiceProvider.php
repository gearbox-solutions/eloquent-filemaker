<?php

namespace GearboxSolutions\EloquentFileMaker\Providers;

use GearboxSolutions\EloquentFileMaker\Middleware\EndSession;
use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Contracts\Http\Kernel;
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
                $config['name'] = $connectionName;

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

        app('router')->aliasMiddleware('fm.end-session', EndSession::class);

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Kernel $kernel)
    {
        // add the middleware to the global middleware so that we always end the FileMaker session
        $kernel->pushMiddleware(EndSession::class);
    }
}
