<?php

namespace GearboxSolutions\EloquentFileMaker\Exceptions;

use Exception;

/**
 * Thrown for errors returned by FileMaker's OData API. The exception code is the HTTP status
 * code of the response (e.g. 404 for a missing record), falling back to the numeric "code"
 * from the OData error body when present.
 */
class FileMakerODataException extends Exception
{
    //
}
