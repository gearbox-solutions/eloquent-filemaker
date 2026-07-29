<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\FMGuardsAttributes;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\FMHasAttributes;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\FMHasRelationships;
use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection as BaseCollection;

abstract class FMModel extends Model
{
    use FMGuardsAttributes;
    use FMHasAttributes;
    use FMHasRelationships;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * FileMaker fields which should be renamed for the purposes of working in this Laravel app. This is useful when
     * FileMaker fields have be inconveniently named.
     *
     * @var array
     */
    protected $fieldMapping = [];

    /**
     * Fields which should not be attempted to be written back to FileMaker. This might be IDs, timestamps, summaries,
     * or calculation fields.
     *
     * @var string[]
     */
    protected $readOnlyFields = [
    ];

    /**
     * The "type" of the primary key ID. FileMaker uses UUID strings by default.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The date format to use when writing to the database.
     *
     * @var string
     */
    protected $dateFormat = 'm/j/Y H:i:s';

    public static function all($columns = ['*'])
    {
        return static::query()->get(
            is_array($columns) ? $columns : func_get_args()
        );
    }

    /**
     * Create a model object from a flat record as returned by the OData API
     *
     * @param  array  $record
     * @return FMModel
     */
    public static function createFromRecord($record)
    {
        return (new static)->hydrateFromRecord($record);
    }

    /**
     * Fill this model's raw attributes directly from a flat OData record, bypassing any
     * mutators/casts (the record already reflects FileMaker's storage representation).
     * Used both for hydrating freshly-fetched records and for hydrating a model in place
     * after a create/refresh response.
     *
     * @return $this
     */
    public function hydrateFromRecord($record)
    {
        $this->setRawAttributes($this->mapRecordAttributes($record), true);
        $this->exists = true;
        // Sync the original data array so we know if it's been modified
        $this->syncOriginal();

        return $this;
    }

    public static function createModelsFromRecordSet(BaseCollection $records): Collection
    {
        // return an empty Eloquent/Collection (or the custom collection specified in the model) if an empty collection was
        // passed in.
        if ($records->count() === 0) {
            return (new static)->newCollection();
        }

        // Records passed in weren't empty, so process the records
        $mappedRecords = $records->map(function ($record) {
            return static::createFromRecord($record);
        });

        // return the filled Eloquent/Collection (or the custom collection specified in the model)
        return (new static)->newCollection($mappedRecords->all());
    }

    /** Fill in data for this existing model with record data from FileMaker
     * @return FMModel
     */
    public function fillFromRecord($record)
    {
        tap($this)->forceFill($this->mapRecordAttributes($record));

        // Sync the original data array so we know if it's been modified
        $this->syncOriginal();

        return $this;
    }

    /**
     * Map a flat OData record's field names through the model's $fieldMapping and apply the
     * empty-string-to-null convention.
     */
    protected function mapRecordAttributes($record): array
    {
        $fieldMapping = $this->getFieldMapping();

        if (! empty($fieldMapping)) {
            $record = collect($record)->mapWithKeys(function ($value, $key) use ($fieldMapping) {
                return [$fieldMapping[$key] ?? $key => $value];
            })->toArray();
        }

        $emptyStringToNull = $this->getConnection()->getConfig()['empty_strings_to_null'] ?? true;
        if ($emptyStringToNull) {
            $record = collect($record)->map(function ($value) {
                return $value === '' ? null : $value;
            })->toArray();
        }

        return $record;
    }

    public function getReadOnlyFields()
    {
        return $this->readOnlyFields;
    }

    /**
     * @return array|null
     */
    public function getFieldMapping()
    {
        return $this->fieldMapping;
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  FMBaseBuilder  $query
     * @return FMEloquentBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new FMEloquentBuilder($query);
    }

    protected function performUpdate(Builder $query)
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->editRecord();

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Perform a model insert operation.
     *
     * @return bool
     */
    protected function performInsert(Builder $query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // First we'll need to create a fresh query instance and touch the creation and
        // update timestamps on this model, which are maintained by us for developer
        // convenience. After, we will just continue saving these model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $query->createRecord();

        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Strip out read-only fields to prepare for a write query. Container (file) values are
     * left as-is here; they're base64-encoded by the connection when the request is sent.
     *
     * @return BaseCollection
     */
    public function getAttributesForFileMakerWrite()
    {
        $fieldData = collect($this->getAttributes());

        $fieldData = $fieldData->intersectByKeys($this->getDirty());

        // Remove any fields which have been marked as read-only so we don't try to write and cause an error
        $fieldData->forget($this->getReadOnlyFields());

        return $fieldData;
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        // we shouldn't ever qualify columns because they could be related data
        // so just return without the table
        return $column;
    }

    /**
     * Reload the current model instance with fresh attributes from the database.
     *
     * @return $this
     */
    public function refresh()
    {
        if (! $this->exists) {
            return $this;
        }

        $fresh = $this->newQueryWithoutScopes()->find($this->getKey());

        if ($fresh === null) {
            return $this;
        }

        $this->setRawAttributes($fresh->attributes);

        $this->load(collect($this->relations)->reject(function ($relation) {
            return $relation instanceof Pivot
                || (is_object($relation) && in_array(AsPivot::class, class_uses_recursive($relation), true));
        })->keys()->all());

        $this->syncOriginal();

        return $this;
    }
}
