<?php


namespace BlueFeather\EloquentFileMaker\Database\Eloquent\Concerns;

use BlueFeather\EloquentFileMaker\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait FMHasRelationships
{

    /**
     * Define a one-to-one relationship.
     *
     * @param string $related
     * @param string|null $foreignKey
     * @param string|null $localKey
     */
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }


    /**
     * Define a one-to-many relationship.
     *
     * @param string $related
     * @param string|null $foreignKey
     * @param string|null $localKey
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasMany(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    /**
     * Instantiate a new BelongsTo relationship.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Database\Eloquent\Model $child
     * @param string $foreignKey
     * @param string $ownerKey
     * @param string $relation
     */
    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey, $relation)
    {
        // custom version of this so we can return our own BelongsTo class with a custom constraint for FM
        return new BelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }


}
