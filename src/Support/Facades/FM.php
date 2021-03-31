<?php


namespace BlueFeather\FileMaker_Laravel\Support\Facades;


use BlueFeather\FileMaker_Laravel\Database\Query\FMBaseBuilder;
use BlueFeather\FileMaker_Laravel\Services\FileMakerConnection;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Facade;


/**
 * @method static FMBaseBuilder performScript($script = null, $param = null)
 * @method static FMBaseBuilder layout($layoutName)
 * @method static FMBaseBuilder table($layoutName)
 * @method static FMBaseBuilder recordId($recordId)
 * @method static FMBaseBuilder script($scriptName)
 * @method static FMBaseBuilder scriptParam($param)
 * @method static FMBaseBuilder scriptPresort($name)
 * @method static FMBaseBuilder scriptPresortParam($param)
 * @method static FMBaseBuilder scriptPrerequest($name)
 * @method static FMBaseBuilder scriptPrerequestParam($param)
 * @method static FMBaseBuilder layoutResponse($name)
 * @method static FMBaseBuilder omit($boolean = true)
 * @method static FMBaseBuilder fieldData($array)
 * @method static FMBaseBuilder portalData($array)
 * @method static FMBaseBuilder setContainer($column, File $file)
 * @method static FMBaseBuilder findByRecordId($recordId)
 * @method static FMBaseBuilder modId(int $modId)
 * @method static FMBaseBuilder portal($portalName)
 *
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
