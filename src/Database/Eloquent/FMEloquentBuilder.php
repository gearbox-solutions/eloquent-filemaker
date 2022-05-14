<?php


namespace BlueFeather\EloquentFileMaker\Database\Eloquent;

use BlueFeather\EloquentFileMaker\Database\Eloquent\Concerns\FMHasRelationships;
use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class FMEloquentBuilder extends Builder
{
    use FMHasRelationships;

    /**
     * @return Collection
     * @throws FileMakerDataApiException
     */
    public function get($columns = ['*'])
    {
        $records = $this->tobase()->get();
        $models = $this->model->createModelsFromRecordSet($records);
        return $models;
    }


    /**
     * Set the affected Eloquent model and instance ids.
     *
     * @param FMModel $model
     * @param int|array $ids
     * @return $this
     */
    public function setModel($model, $ids = [])
    {
        $this->query = new FMBaseBuilder($model->getConnection());

        $this->model = $model;
        $this->query->layout($model->getLayout())
            ->setFieldMapping($model->getFieldMapping());

        return $this;
    }

    /**
     * @return Collection
     * @throws FileMakerDataApiException
     */
    public function all()
    {
        // Set the limit if the user hasn't already set one
        // We're setting a high limit since the data API limits to 100 records by default
        // It seems to break if we add any more 0s than 1000000000000000000
        // Hopefully nobody is really going to hit this limit.... hopefully....
        if ($this->query->limit == null or $this->query->limit == 0) {
            $this->query->limit(1000000000000000000);
        }

        return $this->get();
    }


    /**
     * Determine if any rows exist for the current query.
     * This actually runs the full query, so it doesn't save you any data, just an error capture
     *
     * @return bool
     */
    public function exists()
    {

        // do the query and check for a 401. The query will error if there are no rows which match the request
        try {
            $this->limit(1)->get();
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() == 401) {
                return false;
            } else {
                throw $e;
            }
        }
        // It didn't error, so we have something
        return true;
    }

    /**
     * Determine if no rows exist for the current query.
     *
     * @return bool
     */
    public function doesntExist()
    {
        return !$this->exists();
    }


    /**
     * Add a where clause on the primary key to the query.
     *
     * @param mixed $id
     * @return $this
     */
    public function whereKeyNot($id)
    {

        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereNotIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        if ($id !== null && $this->model->getKeyType() === 'string') {
            $id = (string) $id;
        }

        // If this is our first where clause we can add the omit directly
        if (sizeof($this->wheres) === 0){
            return $this->where($this->model->getKeyName(), '==', $id)->omit();
        }

        // otherwise we need to add a find and omit
        return $this->orWhere($this->model->getKeyName(), '==', $id)->omit();
    }



    public function findByRecordId($recordId)
    {
        $response = $this->query->findByRecordId($recordId);
        $newRecord = $response['response']['data'][0];
        $newModel = $this->model::createFromRecord($newRecord);
        return $newModel;
    }


    /**
     * Get a single column's value from the first result of a query.
     *
     * @param string $column
     * @return mixed
     */
    public function value($column)
    {
        if ($result = $this->first()) {
            return $result->getAttribute($column);
        } else {
            return null;
        }
    }

    /**
     * Delete records from the database.
     *
     * @return mixed
     */
    public function delete()
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->toBase()->recordId($this->model->getRecordId())->delete();
    }

    public function editRecord()
    {
        /** @var FMModel $model */
        $model = $this->model;


        // Map the columns to FileMaker fields and strip out read-only fields/containers
        $fieldsToWrite = $this->model->getAttributesForFileMakerWrite();


        $modifiedPortals = null;
        foreach($fieldsToWrite as $key => $value) {
            // Check if the field is a portal (it should be an array if it is)
            if (is_array($value)) {

                $modifiedPortals[$key] = $this->getOnlyModifiedPortalFields($fieldsToWrite[$key], $this->model->getOriginal($key));
                $fieldsToWrite->forget($key);
            }
        }

        if ($fieldsToWrite->count() > 0 || sizeof($modifiedPortals ?? []) > 0) {
            // we have some regular text fields to update
            // forward this request to a base query builder to execute the edit record request
            $response = $this->query->fieldData($fieldsToWrite->toArray())->portalData($modifiedPortals)->recordId($model->getRecordId())->editRecord();

            // update the model's mod ID from the response
            $this->model->setModId($this->getModIdFromFmResponse($response));
        }


        // also update any container fields which have changed
        // Only attempt to write modified container fields
        $modifiedContainerFields = $this->model->getContainersToWrite();
        foreach ($modifiedContainerFields as $containerField) {
            $eachResponse = $this->query->recordId($model->getRecordId())->setContainer($containerField, $model->getAttribute($containerField));
            $this->model->setModId($this->getModIdFromFmResponse($eachResponse));
        }
    }

    public function createRecord()
    {
        /** @var FMModel $model */
        $model = $this->model;


        // Map the columns to FileMaker fields and strip out read-only fields/containers
        $fieldsToWrite = $this->model->getAttributesForFileMakerWrite();

        // we always need to create the record, even if there are no regular or portal fields which have been set

        // forward this request to a base query builder to execute the create record request
        $response = $this->query->fieldData($fieldsToWrite->toArray())->portalData($model->portalData)->createRecord();

        // Update the model's record ID from the response
        $recordId = $response['response']['recordId'];
        $this->model->setRecordId($recordId);
        // update the model's mod ID from the response
        $this->model->setModId($this->getModIdFromFmResponse($response));

        // also set any container fields which have been set
        // Only attempt to write modified container fields
        $modifiedContainerFields = $this->model->getContainersToWrite();
        foreach ($modifiedContainerFields as $containerField) {
            $eachResponse = $this->query->recordId($model->getRecordId())->setContainer($containerField, $model->getAttribute($containerField));
            $this->model->setModId($this->getModIdFromFmResponse($eachResponse));
        }
    }

    protected function getModIdFromFmResponse($response)
    {
        return $response['response']['modId'];
    }

    public function duplicate()
    {
        $response = $this->query->duplicate($this->model->getRecordId());
        return $response;
    }

    /**
     * Paginate the given query.
     *
     * @param int|null $perPage
     * @param array $columns
     * @param string $pageName
     * @param int|null $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        /** @var FMBaseBuilder $query */
        $query = $this->toBase()->forPage($page, $perPage);

        // prep items and total as null so we can handle 401 errors
        $total = null;
        $results = null;

        // do the query and check for a 401. The query will 401 error if there are no rows which match the request
        try {
            $response = $this->getQuery()->getConnection()->performFind($query);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() == 401) {
                $results = $this->model->newCollection();
                $total = 0;
            } else {
                throw $e;
            }
        }

        // We didn't get a 401 and have received a real response, so parse it for the paginator
        if ($total === null && $results === null) {

            $total = $response['response']['dataInfo']['foundCount'];

            $records = collect($response['response']['data']);

            // start items as an empty array, but fill if the records
            $results = $this->model->createModelsFromRecordSet($records);
        }

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     *
     * Retrieve the "count" result of the query.
     *
     * @param string $columns
     * @return int
     * @throws FileMakerDataApiException
     */
    public function count($columns = '*'): int
    {
        /** @var FMBaseBuilder $query */
        $query = $this->query->limit(1);
        try {
            $response = $this->getQuery()->getConnection()->performFind($query);
            $count = $response['response']['dataInfo']['foundCount'];
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() == 401) {
                $count = 0;
            } else {
                throw $e;
            }
        }
        return $count;
    }

    /**
     * Compares a model's modified portal data and original portal data and returns portal data with only modified fields and recordIds
     *
     * @param $array1 array The modified portal data
     * @param $array2 array The model's original portal data
     * @return array
     */
    protected function getOnlyModifiedPortalFields($array1, $array2): array
    {
        $result = [];
        foreach($array1 as $key => $val) {
            if($array2[$key] != $val){
                // go recursive if we're comparing two arrays
                if(is_array($val)  && is_array($array2[$key])){
                    $result[$key] = $this->getOnlyModifiedPortalFields($val, $array2[$key]);
                } else{
                    // These are normal values, so compare directly
                    $result[$key] = $val;
                    // at least one field is modified, so also set the recordID if it isn't set yet
                    if(!isset($result['recordId'])){
                        $result['recordId'] = $array1['recordId'];
                    }
                }
            } else {
                // The values are equal
            }
        }

        return $result;
    }


}