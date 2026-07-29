<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Eloquent;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerODataException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;

class FMEloquentBuilder extends Builder
{
    /**
     * @return Collection
     *
     * @throws FileMakerODataException
     */
    public function get($columns = ['*'])
    {
        $records = $this->toBase()->get();
        $models = $this->model->createModelsFromRecordSet($records);

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.
        if ($models->isNotEmpty()) {
            $models = $this->eagerLoadRelations($models->all());
        } else {
            $models = $models->all();
        }

        return $this->getModel()->newCollection($models);
    }

    /**
     * Set the affected Eloquent model and instance ids.
     *
     * @param  FMModel  $model
     * @param  int|array  $ids
     * @return $this
     */
    public function setModel($model, $ids = [])
    {
        $this->query = new FMBaseBuilder($model->getConnection());

        $this->model = $model;
        $this->query->from($model->getTable())
            ->setFieldMapping($model->getFieldMapping());

        return $this;
    }

    /**
     * Determine if any rows exist for the current query.
     * This actually runs the full query, so it doesn't save you any data, just an error capture
     *
     * @return bool
     */
    public function exists()
    {
        return $this->limit(1)->get()->isNotEmpty();
    }

    /**
     * Determine if no rows exist for the current query.
     *
     * @return bool
     */
    public function doesntExist()
    {
        return ! $this->exists();
    }

    /**
     * Get a single column's value from the first result of a query.
     *
     * @param  string  $column
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
     * Write the model's dirty attributes to FileMaker. Assumes the query has already been
     * constrained to the model's primary key (see FMModel::performUpdate()).
     */
    public function editRecord()
    {
        $fieldsToWrite = $this->model->getAttributesForFileMakerWrite();

        if ($fieldsToWrite->count() === 0) {
            return;
        }

        $this->query->fieldData($fieldsToWrite->toArray())->editRecord();
    }

    public function createRecord()
    {
        /** @var FMModel $model */
        $model = $this->model;

        $fieldsToWrite = $this->model->getAttributesForFileMakerWrite();

        $record = $this->query->fieldData($fieldsToWrite->toArray())->createRecord();

        // The OData API returns the full created entity (including any auto-entered or
        // calculated field values), so we can hydrate the model directly from the response
        // instead of issuing a separate "refresh" request.
        $model->hydrateFromRecord($record);
    }

    /**
     * Paginate the given query.
     *
     * @param  int|null|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  \Closure|int|null  $total
     * @return LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $query = $this->forPage($page, $perPage)->toBase();
        $response = $query->connection->selectWithCount($query);

        $total = $response['@odata.count'] ?? 0;
        $results = $this->model->createModelsFromRecordSet(collect($response['value'] ?? []));

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }
}
