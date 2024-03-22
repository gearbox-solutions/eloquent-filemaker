<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns;

use DateTime;
use Illuminate\Support\Arr;

trait FMHasAttributes
{
    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        parent::setAttribute($key, $value);

        $value = $this->attributes[$key];

        // Check if we still have a DateTime object due to custom formatting and convert it to a string to write to FM.
        // Normally the SQL grammar would handle converting DateTime objects and SQL doesn't care about extra time data,
        // but FileMaker does, so we have to convert at this point and strip out times.
        //
        // We could convert the DateTime to a string at the time when we're preparing the API call, but at that point
        // we won't be in the model and won't have access to the cast type to determine if we should strip out the
        // time data.

        if ($value instanceof DateTime) {
            $value = $value->format($this->dateFormat);
        }
        // When writing dates the regular datetime format won't work, so we have to get JUST the date value
        // check the key's cast to see if it is cast to a date or custom date:format
        $castType = $this->getCasts()[$key] ?? '';
        $isDate = $castType == 'date' || str_starts_with($castType, 'date:');
        if ($isDate) {
            $value = Arr::first(explode(' ', $value));
        }

        // FileMaker can't handle true and false, so we need to change to 1 and 0
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    protected function castAttribute($key, $value)
    {
        // FileMaker doesn't have `null` as a value, but we should consider an empty string as null for casting purposes
        // since any of the cast types wouldn't normally handle an empty string as a valid value.
        if ($value === '') {
            $value = null;
        }

        return parent::castAttribute($key, $value);
    }
}
