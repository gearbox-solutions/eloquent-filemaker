<?php

namespace BlueFeather\EloquentFileMaker\Support\Facades;

use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Support\Facades\Facade;

/**
 * Executes against DatabaseManager. If DatabaseManager doesn't have the functionality, it uses __call to call against
 * the connection, which would be a FileMakerConnection.
 *
 * @method static FileMakerConnection connection(string $name = null)
 * @method static FMBaseBuilder layout($layoutName)
 * @method static FMBaseBuilder table($layoutName)
 * @method static array setGlobalFields(array $globalFields)
 * @method static FileMakerConnection setRetries(int $retries)
 *
 * @see \Illuminate\Database\DatabaseManager
 * @see FileMakerConnection
 *
 * */
class FM extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'fm';
    }
}
