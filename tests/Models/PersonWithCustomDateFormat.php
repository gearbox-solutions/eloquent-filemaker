<?php

namespace Tests\Models;

/**
 * A model which overrides the package's ISO 8601 default date format, for FileMaker files
 * whose date/timestamp fields are typed as text and stored in a display format.
 */
class PersonWithCustomDateFormat extends Person
{
    protected $dateFormat = 'm/j/Y H:i:s';
}
