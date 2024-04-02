<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\FMGuardsAttributes;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\FMHasAttributes;
use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\FMHasRelationships;
use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;

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
     * The layout to be used when retrieving this model. This is equivalent to the standard laravel $table property and
     * either one can be used.
     */
    protected $layout;

    /**
     * The internal FileMaker record ID. This is not the primary key of the record used in relationships. This field is
     * automatically updated when records are retrieved or saved.
     */
    protected $recordId;

    /**
     * The internal FileMaker ModId which keeps track of the modification number of a particular FileMaker record. This
     * value is automatically set when records are retrieved or saved.
     */
    protected $modId;

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

    public function __construct(array $attributes = [])
    {
        // Laravel uses tables normally, but FileMaker users layouts, so we'll let users set either one for clarity
        // Set table if the user didn't set it and set $layout instead
        if (! $this->table) {
            $this->setTable($this->layout);
        }
        parent::__construct($attributes);
    }

    public static function all($columns = ['*'])
    {
        return static::query()->limit(1000000000000000000)->get(
            is_array($columns) ? $columns : func_get_args()
        );
    }

    /**
     * Create a model object from the returned FileMaker data
     *
     * @param  array  $record
     * @return FMModel
     */
    public static function createFromRecord($record)
    {
        // create a new static instance for the class or child class
        $instance = new static;

        // just get the field data to make it easier to work with
        $fieldData = $record['fieldData'];
        $portalData = $record['portalData'];

        $fieldMapping = $instance->getFieldMapping();

        // Only do field mapping if one has been defined
        if (! empty($fieldMapping)) {
            // Fill the attributes from fieldMapping with the fieldData retrieved from FileMaker
            $fieldData = collect($fieldData)->mapWithKeys(function ($value, $key) use ($fieldMapping) {
                return [$fieldMapping[$key] ?? $key => $value];
            })->toArray();
        }

        // check our config to see if we should map empty strings to null - users may decide they don't want this
        if (config('eloquent-filemaker.empty_strings_to_null', true)) {
            // map each value to null if it's an empty string
            $fieldData = collect($fieldData)->map(function ($value) {
                return $value === '' ? null : $value;
            })->toArray();
        }

        // fill in the field data we've mapped and retrieved
        $instance->forceFill($fieldData);

        // fill in the portal data we've mapped and retrieved
        $instance->forceFill($portalData);

        $recordId = $record['recordId'];
        $modId = $record['modId'];
        $instance->setRecordId($recordId);
        $instance->setModId($modId);
        $instance->exists = true;
        // Sync the original data array so we know if it's been modified
        $instance->syncOriginal();

        return $instance;
    }

    public static function createModelsFromRecordSet(BaseCollection $records): Collection
    {
        // return an empty Eloquent/Collection (or a collection of the specific model type) if an empty collection was
        // passed in.
        if ($records->count() === 0) {
            return (new static())->newCollection([]);
        }

        // Records passed in weren't empty, so process the records
        $mappedRecords = $records->map(function ($record) {
            return static::createFromRecord($record);
        });

        // return the filled Eloquent/Collection (or a collection of the specific model type if possible)
        return (new static())->newCollection($mappedRecords->all());
    }

    /** Fill in data for this existing model with record data from FileMaker
     * @return FMModel
     */
    public function fillFromRecord($record)
    {
        // just get the field data to make it easier to work with
        $fieldData = $record['fieldData'];
        $portalData = $record['portalData'];

        $fieldMapping = $this->getFieldMapping();

        // Only do field mapping if one has been defined
        if (! empty($fieldMapping)) {
            // Fill the attributes from fieldMapping with the fieldData retrieved from FileMaker
            $fieldData = collect($fieldData)->mapWithKeys(function ($value, $key) use ($fieldMapping) {
                return [$fieldMapping[$key] ?? $key => $value];
            })->toArray();
        }

        // fill in the field data we've mapped and retrieved
        tap($this)->forceFill($fieldData);

        // fill in the portal data we've retrieved
        tap($this)->forceFill($portalData);

        // Sync the original data array so we know if it's been modified
        $this->syncOriginal();

        return $this;
    }

    public function getRecordId()
    {
        return $this->recordId;
    }

    public function setRecordId($recordId)
    {
        $this->recordId = $recordId;
    }

    /**
     * @return int|null
     */
    public function getModId()
    {
        return $this->modId;
    }

    /**
     * @param  int  $modId
     */
    public function setModId($modId): void
    {
        $this->modId = $modId;
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

    public function duplicate()
    {

        // Check to make sure this model exists before attempting to duplicate
        if ($this->getRecordId() === null) {
            // This doesn't exist yet, so exit here
            return false;
        }

        // model events for duplicating, like create/update
        if ($this->fireModelEvent('duplicating') === false) {
            return false;
        }

        $response = $this->newQuery()->duplicate();
        // Get the newly created recordId and return it
        $newRecordId = $response['response']['recordId'];
        $this->fireModelEvent('duplicated', false);

        return $newRecordId;
    }

    /**
     * @return string
     */
    public function getLayout()
    {
        return $this->getTable();
    }

    /**
     * @param  mixed  $layout
     */
    public function setLayout($layout): void
    {
        $this->setTable($layout);
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

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            try {
                $query->editRecord();
            } catch (FileMakerDataApiException $e) {
                // attempting to update and not actually modifying a record just returns a 0 by default to show no records were modified
                // If we don't actually modify anything it isn't considered an error in Laravel and we just continue
                if ($e->getCode() !== 101) {
                    // There was some error other than record missing, so throw it
                    throw $e;
                }
            }

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Set the keys for a save update query.
     *
     * @param  Builder  $query
     * @return Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->toBase()->recordId($this->recordId);

        return $query;
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

        // If the model has an incrementing key, we can use the "insertGetId" method on
        // the query builder, which will give us back the final inserted ID for this
        // table from the database. Not all tables have to be incrementing though.
        $attributes = $this->getAttributesForInsert();

        if ($this->getIncrementing()) {
            $query->createRecord();
            // perform a refresh after the insert to get the generated primary key / ID and calculated data
            $this->setRawAttributes(
                $this->findByRecordId($this->recordId)->attributes
            );
        }

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        else {
            if (empty($attributes)) {
                return true;
            }

            $query->createRecord();
        }

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Strip out containers and read-only fields to prepare for a write query
     *
     * @return BaseCollection
     */
    public function getAttributesForFileMakerWrite()
    {
        $fieldData = collect($this->getAttributes());

        $fieldData = $fieldData->intersectByKeys($this->getDirty());

        // Remove any fields which have been marked as read-only so we don't try to write and cause an error
        $fieldData->forget($this->getReadOnlyFields());

        // Remove any fields which have been set to write a file, as they should be handled as containers
        foreach ($fieldData as $key => $field) {
            // remove any containers to be written.
            // users can set the field to be a File, UploadFile, or array [$file, 'MyFile.pdf']
            if ($this->isContainer($field)) {
                $fieldData->forget($key);
            }
        }

        return $fieldData;
    }

    public function getContainersToWrite()
    {
        // get dirty fields
        $fieldData = collect($this->getAttributes());
        $fieldData = $fieldData->intersectByKeys($this->getDirty());

        $containers = collect([]);

        // Track any fields which have been set to write a file, as they should be handled as containers
        foreach ($fieldData as $key => $field) {
            // remove any containers to be written.
            if ($this->isContainer($field)) {
                $containers->push($key);
            }
        }

        return $containers;
    }

    protected function isContainer($field)
    {

        // if this is a file then we know it's a container
        if ($this->isFile($field)) {
            return true;
        }

        // if it's an array, it could be a file => filename key-value pair.
        // it's a conainer if the first object in the array is a file
        if (is_array($field) && count($field) === 2 && $this->isFile($field[0])) {
            return true;
        }

        return false;
    }

    protected function isFile($object)
    {
        return is_a($object, File::class) ||
        is_a($object, UploadedFile::class);
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table ?? $this->layout ?? Str::snake(Str::pluralStudly(class_basename($this)));
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
        // make sure we have a FileMaker internal recordId
        if ($this->recordId === null) {
            return $this;
        }

        $this->setRawAttributes(
            $this->findByRecordId($this->recordId)->attributes
        );

        $this->load(collect($this->relations)->reject(function ($relation) {
            return $relation instanceof Pivot
                || (is_object($relation) && in_array(AsPivot::class, class_uses_recursive($relation), true));
        })->keys()->all());

        $this->syncOriginal();

        return $this;
    }
}
