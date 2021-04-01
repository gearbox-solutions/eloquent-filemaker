<?php

namespace BlueFeather\EloquentFileMaker\Database\Eloquent;

use BlueFeather\EloquentFileMaker\Database\Eloquent\Concerns\FMHasRelationships;
use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

abstract class FMModel extends Model
{

    use FMHasRelationships;


    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * FileMaker fields which should be renamed for the purposes of working in this Laravel app. This is useful when FileMaker fields have be inconveniently named.
     * @var array
     */
    protected $fieldMapping = [];

    /**
     * Fields which should not be attempted to be written back to FileMaker. This might be IDs, timestamps, summaries, or calculation fields.
     * @var string[]
     */
    protected $readOnlyFields = [
        'id',
        'creationTimestamp',
        'creationAccount',
        'modificationTimestamp',
        'modificationAccount',
    ];

    /**
     * The name of the database connection to be used from the database.php config file.
     * @var
     */
    protected $database;

    /**
     * The layout to be used when retrieving this model. This is equivalent to the standard laravel $table property and either one can be used.
     * @var
     */
    protected $layout;

    /**
     * The internal FileMaker record ID. This is not the primary key of the record used in relationships. This field is automatically updated when records are retrieved or saved.
     * @var
     */
    protected $recordId;

    /**
     * The internal FileMaker ModId which keeps track of the modification number of a particular FileMaker record. This value is automatically set when records are retrieved or saved.
     * @var
     */
    protected $modId;

    /**
     * A list of the container fields for this model. These containers need to be listed specifically so that they can have their data stored correctly as part of the save() method;
     * @var array
     */
    protected $containerFields = [];

    public function __construct(array $attributes = [])
    {
        // FileMaker users tables, but the connections use layouts, so we'll use that as a standard
        $this->setTable($this->layout ?? $this->table);
        parent::__construct($attributes);
    }

    /**
     * Get a new query builder for the model's table.
     *
     */
    public function newQuery()
    {
        return app(FMEloquentBuilder::class)->setModel($this);
    }


    /**
     * Create a model object from the returned FileMaker data
     *
     * @param array $record
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
        if (!empty($fieldMapping)) {
            // Fill the attributes from fieldMapping with the fieldData retrieved from FileMaker
            $fieldData = collect($fieldData)->mapWithKeys(function ($value, $key) use ($fieldMapping) {
                return [$fieldMapping[$key] ?? $key => $value];
            })->toArray();
        }

        // fill in the data we've mapped and retrieved
        $instance = tap($instance)->forceFill($fieldData);

        // fill in the data we've mapped and retrieved
        $instance = tap($instance)->forceFill($portalData);

        $recordId = $record['recordId'];
        $modId = $record['modId'];
        $instance->setRecordId($recordId);
        $instance->setModId($modId);
        $instance->exists = true;
        // Sync the original data array so we know if it's been modified
        $instance->syncOriginal();
        return $instance;

    }

    /**
     * @param Collection $records
     * @return Collection
     */
    public static function createModelsFromRecordSet(Collection $records): Collection
    {

        // start with an empty collection
        $collection = collect([]);

        // return an empty collection if an empty collection was passed in.
        if ($records->count() === 0){
            return $collection;
        }

        // Records passed in weren't empty, so process the records
        foreach ($records as $record) {
            $model = static::createFromRecord($record);
            $collection->push($model);
        }

        return $collection;
    }


    /** Fill in data for this existing model with record data from FileMaker
     * @param $record
     * @return FMModel
     */
    public function fillFromRecord($record)
    {
        // just get the field data to make it easier to work with
        $fieldData = $record['fieldData'];
        $portalData = $record['portalData'];

        $fieldMapping = $this->getFieldMapping();

        // Only do field mapping if one has been defined
        if (!empty($fieldMapping)) {
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
     * @param int $modId
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

    public function getContainerFields()
    {
        return $this->containerFields;
    }


    /**
     * @return string
     */
    public function getLayout()
    {
        return $this->getTable();
    }

    /**
     * @param mixed $layout
     */
    public function setLayout($layout): void
    {
        $this->setTable($layout);
    }

    /**
     * @return mixed
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * @param mixed $database
     */
    public function setDatabase($database): void
    {
        $this->database = $database;
    }


    /**
     * Get the database connection for the model.
     *
     */
    public function getConnection()
    {
        return app(FileMakerConnection::class)
            ->setDatabaseName($this->getDatabase());
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param FMBaseBuilder $query
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
            $this->newQuery()->setModel($this)->editRecord();
            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Perform a model insert operation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
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
        $attributes = $this->getAttributes();

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        if (empty($attributes)) {
            return true;
        }

        $attributes = $this->prepareAttributesForFileMaker($attributes)->toArray();

        $response = $query->insert($attributes);
        // inserting doesn't get us the ID back, so we have to set the record ID and re-query to get updates
        $recordId = $response['response']['recordId'];
        $this->setRecordId($recordId);

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
     * @param FMModel $model
     * @return Collection
     */
    protected function prepareAttributesForFileMaker($attributes)
    {

        $fieldData = collect($attributes);

        // Remove any fields which have been marked as read-only so we don't try to write and cause an error
        $fieldData->forget($this->getReadOnlyFields());

        // Remove any container fields
        $fieldData->forget($this->getContainerFields());
        return $fieldData;
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->layout ?? $this->table ?? Str::snake(Str::pluralStudly(class_basename($this)));
    }


}
