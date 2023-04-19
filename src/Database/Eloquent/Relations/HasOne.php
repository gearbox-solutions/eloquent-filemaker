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

    protected function getKeys(array $models, $key = null)
    {
        return collect($models)->map(function ($value) use ($key) {
            return $key ? $value->getAttribute($key) : $value->getKey();
        })->values()->filter()->unique(null, true)->sort()->all();
    }
}