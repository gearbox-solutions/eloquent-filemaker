<?php


namespace BlueFeather\EloquentFileMaker\Support\Facades;


use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Facade;


/**
 *
 * Executes against DatabaseManager. If DatabaseManager doesn't have the functionality, it uses __call to call against
 * the connection, which would be a FileMakerConnection.
 *
 * @method static FileMakerConnection connection(string $name = null)
 * @method static array performScript($script = null, $param = null)
 * @method static array executeScript($script = null, $param = null)
 * @method static FMBaseBuilder layout($layoutName)
 * @method static FMBaseBuilder table($layoutName)
 * @method static FMBaseBuilder fieldData($array)
 * @method static FMBaseBuilder portalData($array)
 * @method static FMBaseBuilder setContainer($column, File $file)
 * @method static FMBaseBuilder findByRecordId($recordId)
 * @method static FMBaseBuilder modId(int $modId)
 * @method static FMBaseBuilder portal($portalName)
 *
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
