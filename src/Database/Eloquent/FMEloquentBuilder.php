<?php


namespace BlueFeather\EloquentFileMaker\Database\Eloquent;

use BlueFeather\EloquentFileMaker\Database\Eloquent\Concerns\FMHasRelationships;
use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class FMEloquentBuilder extends Builder
{
    use FMHasRelationships;


    public function __construct(FMBaseBuilder $query)
    {
        Parent::__construct($query);
    }


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

    public function all()
    {
        // Set the limit if the user hasn't already set one
        // We're setting a high limit since the data API limits to 100 records by default
        // It seems to break if we add any more 0s than 1000000000000000000
        // Hopefully nobody is really going to hit this limit.... hopefully....
        if ($this->query->limit == null or $this->query->limit == 0) {
            $this->query->limit(1000000000000000000);
        }

        $records = $this->query->get($this);
        $models = $this->model->createModelsFromRecordSet($records);
        return $models;
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
    public function whereKey($id)
    {
        return $this->where($this->model->getKeyName(), $id);
    }

    /**
     * Add a where clause on the primary key to the query.
     *
     * @param mixed $id
     * @return $this
     */
    public function whereKeyNot($id)
    {
        return $this->where($this->model->getKeyName(), $id)->omit();
    }


    /** Find a model by its primary key
     *
     * @param $id
     */
    public function find($id, $columns = ['*'])
    {
        return $this->where($this->model->getKeyName(), $id)->first();
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

    /**
     * Strip out containers and read-only fields to prepare for a write query
     *
     * @param FMModel $model
     * @return Collection
     */
    protected function prepareFieldDataForFileMaker(FMModel $model)
    {

        $fieldData = collect($model->toArray());
//        $fieldMapping = $model->getFieldMapping();
//
//        // Only try field mapping if the user has specified a mapping
//        // otherwise map directly
//        if (!empty($fieldMapping)) {
//            // Translate Laravel model fields into FileMaker fields using the field mapping
//            $fieldData = collect($fieldMapping)->mapWithKeys(function ($key, $value) use ($fieldData, $fieldMapping) {
//                return [$value => $fieldData[$key] ?? null];
//            });
//        }

        // Remove any fields which have been marked as read-only so we don't try to write and cause an error
        $fieldData->forget($model->getReadOnlyFields());

        // Remove any container fields
        $fieldData->forget($model->getContainerFields());
        return $fieldData;
    }

    public function editRecord()
    {
        $model = $this->model;


        // Map the columns to FileMaker fields and strip out read-only fields/containers
        $editableFields = $this->prepareFieldDataForFileMaker($model);

        // Check if there's anything for us to modify on the record itself
        $modifiedFields = array_intersect(array_keys($model->getDirty()), $editableFields->keys()->toArray());
        if (sizeof($modifiedFields) > 0) {
            // we have some regular text fields to update
            // forward this request to a base query builder to execute the edit record request
            $response = $this->query->fieldData($editableFields->toArray())->portalData($model->portalData)->recordId($model->getRecordId())->editRecord();

            // update the model's mod ID from the response
            $this->model->setModId($this->getModIdFromFmResponse($response));
        }


        // also update any container fields which have changed
        // Only attempt to write modified container fields
        $modifiedContainerFields = array_intersect(array_keys($model->getDirty()), $model->getContainerFields());
        foreach ($modifiedContainerFields as $containerField) {
            $eachResponse = $this->query->recordId($model->getRecordId())->setContainer($containerField, $model->getAttribute($containerField));
            $this->model->setModId($this->getModIdFromFmResponse($eachResponse));
        }

        return true;
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

//        $results = ($total = $this->toBase()->getCountForPagination())
//            ? $this->forPage($page, $perPage)->get($columns)
//            : $this->model->newCollection();

        /** @var FMBaseBuilder $query */
        $query = $this->getQuery()->forPage($page, $perPage);


        // prep items and total as null so we can handle 401 errors

        $total = null;
        $items = null;
        // do the query and check for a 401. The query will 401 error if there are no rows which match the request
        try {
            $response = $this->getQuery()->getConnection()->performFind($query);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() == 401) {
                $items = collect([]);
                $total = 0;
            } else {
                throw $e;
            }
        }

        // We didn't get a 401 and have received a real response, so parse it for the paginator
        if ($total === null && $items === null) {

            $total = $response['response']['dataInfo']['foundCount'];

            $records = collect($response['response']['data']);

            // start items as an empty array, but fill if the records
            $items = $this->model->createModelsFromRecordSet($records);
        }

        return $this->paginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);

    }


}
