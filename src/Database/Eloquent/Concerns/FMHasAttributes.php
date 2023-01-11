<?php

namespace BlueFeather\EloquentFileMaker\Database\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;

trait FMHasAttributes
{
    use HasAttributes;

    /**
     * Determine whether a value is Date / DateTime castable for inbound manipulation.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isDateCastable($key)
    {
        // We need to also cast timestamps as
        return $this->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp']);
    }

}