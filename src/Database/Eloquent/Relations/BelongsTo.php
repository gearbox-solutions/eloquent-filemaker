<?php


namespace BlueFeather\EloquentFileMaker\Database\Eloquent\Relations;


class BelongsTo extends \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            // For belongs to relationships, which are essentially the inverse of has one
            // or has many relationships, we need to actually query on the primary key
            // of the related models matching on the foreign key that's on a parent.
            $table = $this->related->getTable();

            $this->query->where($this->ownerKey, $this->child->{$this->foreignKey});
        }
    }
}