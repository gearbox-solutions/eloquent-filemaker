<?php

namespace GearboxSolutions\EloquentFileMaker\Support\Facades;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;

/**
 * Executes against DatabaseManager. If DatabaseManager doesn't have the functionality, it uses __call to call against
 * the connection, which would be a FileMakerConnection.
 *
 * @method static FMBaseBuilder table($table)
 * @method static FileMakerConnection connection(string $name = null)
 * @method static FileMakerConnection setRetries(int $retries)
 * @method static array getTableMetadata(string $table)
 *
 * @see DatabaseManager
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
