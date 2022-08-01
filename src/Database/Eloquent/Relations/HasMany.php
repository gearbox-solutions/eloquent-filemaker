<?php

namespace BlueFeather\EloquentFileMaker\Database\Eloquent\Relations;

class HasMany extends \Illuminate\Database\Eloquent\Relations\HasMany
{
    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->query->where($this->foreignKey, '==', $this->getParentKey());
        }
    }
}
