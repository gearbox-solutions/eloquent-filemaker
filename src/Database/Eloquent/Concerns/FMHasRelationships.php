<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMModel;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Relations\BelongsTo;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Relations\HasMany;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Model;

trait FMHasRelationships
{
    use HasRelationships {
        HasRelationships::hasOne as hasOneBase;
        HasRelationships::hasMany as hasManyBase;
        HasRelationships::newBelongsTo as newBelongsToBase;
        HasRelationships::newHasMany as newHasManyBase;
        HasRelationships::morphMany as morphManyBase;
        HasRelationships::newHasOne as newHasOneBase;
    }

    /**
     * Define a one-to-one relationship.
     *
     * @param  string  $related
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     */
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        if (! ((new $related) instanceof FMModel)) {
            return $this->hasOneBase($related, $foreignKey, $localKey);
        }

        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     *
     * @param  string  $related
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        if (! ((new $related) instanceof FMModel)) {
            return $this->hasManyBase($related, $foreignKey, $localKey);
        }

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
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @param  string  $relation
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey, $relation)
    {
        if (! ($query->newModelInstance() instanceof FMModel)) {
            return $this->newBelongsToBase($query, $child, $foreignKey, $ownerKey, $relation);
        }

        // custom version of this so we can return our own BelongsTo class with a custom constraint for FM
        return new BelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Instantiate a new HasMany relationship.
     *
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        if (! ($query->newModelInstance() instanceof FMModel)) {
            return $this->newHasManyBase($query, $parent, $foreignKey, $localKey);
        }

        // custom version of this so we can return our own BelongsTo class with a custom constraint for FM
        return new HasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Define a polymorphic one-to-many relationship.
     *
     * @param  string  $related
     * @param  string  $name
     * @param  string|null  $type
     * @param  string|null  $id
     * @param  string|null  $localKey
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function morphMany($related, $name, $type = null, $id = null, $localKey = null)
    {
        if (! ((new $related) instanceof FMModel)) {
            return $this->morphManyBase($related, $name, $type, $id, $localKey);
        }

        $instance = $this->newRelatedInstance($related);

        // Here we will gather up the morph type and ID for the relationship so that we
        // can properly query the intermediate table of a relation. Finally, we will
        // get the table and create the relationship instances for the developers.
        [$type, $id] = $this->getMorphs($name, $type, $id);

        $table = $instance->getTable();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphMany($instance->newQuery(), $this, $type, $id, $localKey);
    }

    /**
     * Instantiate a new HasOne relationship.
     *
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    protected function newHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        if (! ($query->newModelInstance() instanceof FMModel)) {
            return $this->newHasOneBase($query, $parent, $foreignKey, $localKey);
        }

        // custom version of this so we can return our own BelongsTo class with a custom constraint for FM
        return new HasOne($query, $parent, $foreignKey, $localKey);
    }
}
