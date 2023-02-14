<?php


namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent\Relations;


class HasOne extends \Illuminate\Database\Eloquent\Relations\HasOne
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