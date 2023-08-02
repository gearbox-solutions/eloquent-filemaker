<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Query;

use DateTimeInterface;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FMBaseBuilder extends Builder
{
    /**
     * The internal FileMaker Record ID to act on
     */
    protected $recordId;

    /**
     * An array of fields to map to FileMaker fields
     *
     * @var array
     */
    protected $fieldMapping = [];

    /**
     * The name of the FileMaker script to be run after the action specified by the API call and after the subsequent sort.
     *
     * @var string
     */
    public $script;

    /**
     * The text string to use as a parameter for the script that was named by script.
     *
     * @var string
     */
    public $scriptParam;

    /**
     * The name of the script to be run before the action specified by the API call and the subsequent sort.
     *
     * @var string
     */
    public $scriptPrerequest;

    /**
     * The text string to use as a parameter for the script that was named by script.prerequest.
     *
     * @var string
     */
    public $scriptPrerequestParam;

    /**
     * The name of the script to be run after the action specified by the API call but before the subsequent sort.
     *
     * @var string
     */
    public $scriptPresort;

    /**
     * The text string to use as a parameter for the script that was named by script.presort.
     *
     * @var string
     */
    public $scriptPresortParam;

    /**
     * The layout to switch to when processing response.
     *
     * @var string
     */
    public $layoutResponse;

    /**
     * Field data to be used when creating or editing a record;
     *
     * @var array
     */
    public $fieldData;

    /**
     * An array of global fields to set
     *
     * @var array
     */
    public $globalFields = [];

    /**
     * An array of portals which should have limits set it the response.
     * The default limit is 50 records if no value is specified.
     */
    public array $limitPortals = [];

    /**
     * An array of portals which should have the offset set it the response.
     * This is actually the "starting record", and so the default value is 1.
     */
    public array $offsetPortals = [];

    /**
     * @var int The index of the current request in the find request array
     */
    protected int $currentFindRequestIndex = -1;

    public const ASCEND = 'ascend';

    public const DESCEND = 'descend';

    /**
     * An array of portal data to be used when creating or updating a record
     *
     * @var array
     */
    public $portalData;

    /**
     * An array of portal objects to return with the record. Not setting this value will return all portals on the layout.
     *
     * @var array
     */
    public $portal = [];

    /**
     * @var int
     */
    public $modId;

    /**
     * All of the available clause operators.
     *
     * @var string[]
     */
    public $operators = [
        '=', '==', '≠', '!', '<', '>', '<=', '≤', '>=', '≥', '~',
    ];

    public $containerFieldName;

    public $containerFile;

    /**
     * Array to track the whereIn clauses because FM processes WhereIns differently than other DB engines
     *
     * @var array
     */
    protected $whereIns = [];

    /**
     * Flag to be used to enforce that FileMaker Data API gives us an empty set instead of erroring or returning unexpected records
     *
     * @var bool = false
     */
    protected $forceHighOffset = false;

    public function isForcingHighOffset()
    {
        return $this->forceHighOffset;
    }

    /**
     * Add a basic where clause to the query.
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): FMBaseBuilder
    {
        $shouldBeOmit = false;

        if (Str::contains($boolean, 'not')) {
            $shouldBeOmit = true;
            $boolean = trim(str_replace('not', '', $boolean));
        }

        // This is an "orWhere" type query, so add a find request and then work from there
        if ($boolean === 'or' || $shouldBeOmit) {
            $this->addFindRequest();

            if ($shouldBeOmit) {
                $this->omit();
            }
        }

        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        //
        // If the first value is an array it means the second value is an omit for the whole request
        if (is_array($column)) {
            foreach ($column as $eachColumn => $eachValue) {
                $this->where($eachColumn, $eachValue);
            }

            return $this;
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $currentFind = $this->getCurrentFind();

        $currentFind[$this->getMappedFieldName($column)] = $operator . $value;

        // add the where clause KvP to the last item in the array of wheres
        $this->updateCurrentFind($currentFind);

        return $this;
    }

    /**
     * Delete records from the database.
     *
     * @param  null  $recordId
     *
     * @throws FileMakerDataApiException
     */
    public function delete($id = null): int
    {
        // If an ID is passed to the method we will delete the record with this internal FileMaker record ID
        if (! is_null($id)) {
            $this->where($this->defaultKeyName(), '=', $id);
        }
        $this->applyBeforeQueryCallbacks();

        // Check if we have a record ID to delete or if this is a query for a bulk delete
        if ($this->getRecordId() === null) {
            // There's no individual record ID to delete, so do a bulk delete
            return $this->bulkDeleteFromQuery();
        }

        try {
            $this->connection->deleteRecord($this);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() === 101) {
                // no record was found to be deleted, return modified count of 0
                return 0;
            } else {
                throw $e;
            }
        }
        // we deleted the record, return modified count of 1
        return 1;
    }

    /**
     * Do a bulk delete from a where query
     *
     *
     * @throws FileMakerDataApiException
     */
    protected function bulkDeleteFromQuery(): int
    {
        $records = $this->get();
        $deleteCount = 0;
        foreach ($records as $record) {
            try {
                $recordId = $record['recordId'];
                $this->deleteByRecordId($recordId);
                // increment our delete counter if we didn't hit an exception
                $deleteCount++;
            } catch (FileMakerDataApiException $e) {
                if ($e->getCode() === 101) {
                    // no record was found to be deleted
                    // continue on to the next record to attempt to delete without incrementing $deleteCount
                } else {
                    throw $e;
                }
            }
        }
        // Return the count of deleted records
        return $deleteCount;
    }

    /**
     * Delete a record using the internal FileMaker record ID
     *
     *
     * @throws FileMakerDataApiException
     */
    public function deleteByRecordId(int $recordId): int
    {
        $this->recordId = $recordId;

        try {
            $this->connection->deleteRecord($this);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() === 101) {
                // no record was found to be deleted, return modified count of 0
                return 0;
            } else {
                throw $e;
            }
        }
        // we deleted the record, return modified count of 1
        return 1;
    }

    /**
     * Returns the internal FileMaker record ID returned in a previous query, used for things like edits and deletes. This is not the primary key in your database.
     *
     * @return mixed
     */
    public function getRecordId()
    {
        return $this->recordId;
    }

    /**
     * Set the internal FileMaker Record ID to be used for these queries
     *
     * @param  int  $recordId
     */
    public function recordId($recordId)
    {
        $this->recordId = $recordId;

        return $this;
    }

    public function orderBy($column, $direction = self::ASCEND): FMBaseBuilder
    {
        $this->appendSortOrder($column, $direction);

        return $this;
    }

    /**
     * Alias for orderBy
     *
     * @return $this
     */
    public function sort($column, string $direction = self::ASCEND): FMBaseBuilder
    {
        return $this->orderBy($column, $direction);
    }

    /**
     * Convenience method for sorting in descending order
     */
    public function orderByDesc($column): FMBaseBuilder
    {
        return $this->orderBy($column, self::DESCEND);
    }

    protected function appendSortOrder($column, $direction)
    {
        $this->orders[] = ['fieldName' => $this->getMappedFieldName($column), 'sortOrder' => $direction];
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param  int  $value
     * @return $this
     */
    public function limit($value): FMBaseBuilder
    {
        $this->limit = $value;

        return $this;
    }

    public function limitPortal(string $portalName, int $limit): FMBaseBuilder
    {
        $this->limitPortals[] = ['portalName' => $portalName, 'limit' => $limit];

        return $this;
    }

    /**
     * Set an offset for a given portal.
     * This is actually the "starting record", and so the default value is 1.
     */
    public function offsetPortal(string $portalName, int $startingRecord): FMBaseBuilder
    {
        $this->offsetPortals[] = ['portalName' => $portalName, 'offset' => $startingRecord];

        return $this;
    }

    public function offset($value): FMBaseBuilder
    {
        $this->offset = $value;

        return $this;
    }

    public function script($scriptName, $param = null): FMBaseBuilder
    {
        $this->script = $scriptName;

        // set the script parameter if one was passed in
        if ($param) {
            $this->scriptParam = $param;
        }

        return $this;
    }

    public function scriptParam(string $param): FMBaseBuilder
    {
        $this->scriptParam = $param;

        return $this;
    }

    public function scriptPresort(string $scriptName, $param = null): FMBaseBuilder
    {
        $this->scriptPresort = $scriptName;

        // set the script parameter if one was passed in
        if ($param) {
            $this->scriptPresortParam = $param;
        }

        return $this;
    }

    public function scriptPresortParam(string $param): FMBaseBuilder
    {
        $this->scriptPresortParam = $param;

        return $this;
    }

    public function scriptPrerequest(string $scriptName, string $param = null): FMBaseBuilder
    {
        $this->scriptPrerequest = $scriptName;

        // set the script parameter if one was passed in
        if ($param) {
            $this->scriptPrerequestParam = $param;
        }

        return $this;
    }

    public function scriptPrerequestParam(string $param): FMBaseBuilder
    {
        $this->scriptPrerequestParam = $param;

        return $this;
    }

    public function layoutResponse(string $layoutName): FMBaseBuilder
    {
        $this->layoutResponse = $layoutName;

        return $this;
    }

    /**
     * @return Collection
     *
     * @throws FileMakerDataApiException
     */
    public function get($columns = ['*'])
    {
        $records = collect(Arr::get($this->getData(), 'response.data', []));

        // filter to only requested columns
        if ($columns !== ['*']) {
            $records = $records->intersectByKeys(array_flip($columns));
        }

        return $records;
    }

    public function getData()
    {
        $this->computeWhereIns();

        // Run the query and catch a 401 error if there are no records found - just return an empty collection
        try {
            return $this->connection->performFind($this);
        } catch (FileMakerDataApiException $e) {
            throw_if($e->getCode() !== 401, $e);

            return [];
        }
    }

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $response = $this->forPage($page, $perPage)->getData();

        $total = Arr::get($response, 'response.dataInfo.foundCount', 0);
        $results = collect(Arr::get($response, 'response.data'));

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Gets the gets the name of the mapped FileMaker field for a particular column
     *
     * @return string
     */
    protected function getMappedFieldName(string $column)
    {
        // remap the field name if the dev specified a mapping
        return array_flip($this->getFieldMapping())[$column] ?? $column;
    }

    /**
     * A helper function to map an entire array of fields and data to their FileMaker field names
     *
     * @param $array array An array of columns and their values
     */
    protected function mapFieldNamesForArray(array $array): array
    {
        $mappedArray = [];
        foreach ($array as $column => $value) {
            $mappedArray[$this->getMappedFieldName($column)] = $value;
        }

        return $mappedArray;
    }

    public function getFieldMapping(): array
    {
        return $this->fieldMapping;
    }

    /**
     * @param  array  $fieldMapping
     */
    public function setFieldMapping($fieldMapping): void
    {
        $this->fieldMapping = $fieldMapping;
    }

    /**
     * Sets the current find request as an omit.
     * Optionally may pass false as a parameter to make a request NOT an omit if it was already set
     *
     * @param  bool  $boolean
     */
    public function omit($boolean = true): FMBaseBuilder
    {
        $currentFind = $this->getCurrentFind();

        $currentFind['omit'] = $boolean ? 'true' : 'false';

        $this->updateCurrentFind($currentFind);

        return $this;
    }

    /**
     * we should add this where clause as an AND to the current find request
     * This allows us to chain wheres
     * Create a new find array if null
     */
    protected function getCurrentFind()
    {
        if ($this->currentFindRequestIndex === -1) {
            $this->addFindRequest();
        }

        return $this->wheres[$this->currentFindRequestIndex];
    }

    protected function updateCurrentFind($find)
    {
        $this->wheres[$this->currentFindRequestIndex] = $find;
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        dump([$column, $values, $boolean, $not, $this->currentFindRequestIndex]);
        if ($boolean === 'or' || $not) {
            $this->addFindRequest();

            if ($not) {
                $this->omit();
            }
        }

        dump($this->currentFindRequestIndex);

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        // We don't need the current find request but in the case that 0 finds are already performed,
        // this will create the first one.
        $this->getCurrentFind();

        $this->whereIns[] = [
            'column' => $this->getMappedFieldName($column),
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
            'findRequestIndex' => $this->currentFindRequestIndex,
        ];

        return $this;
    }

    protected function computeWhereIns()
    {
        // If no where in clauses return
        if (empty($this->whereIns)) {
            return;
        }

        $whereInRequests = collect($this->whereIns)->mapToGroups(function ($whereIn) {
            $finds = [];

            // If the list of values in a whereIn clause is empty we want the end query to return an empty set instead of other records.
            if (empty($whereIn['values'])) {
                $this->forceHighOffset = true;

                if ($this->isWheresEmpty()) {
                    $finds[] = [
                        $whereIn['column'] => '=',
                    ];
                }
            } else {
                foreach ($whereIn['values'] as $value) {
                    $find = [
                        $whereIn['column'] => $value,
                    ];

                    if ($whereIn['not']) {
                        $find['omit'] = true;
                    }

                    $finds[] = $find;
                }
            }

            return [$whereIn['findRequestIndex'] => $finds];
        });

        if ($this->isWheresEmpty()) {
            $this->wheres = $whereInRequests->flatten(2)->toArray();

            return;
        }

        $newWheres = [];

        // loop through each where
        // If it is an omit, skip it
        // If the where in is an omit, skip it
        foreach ($this->wheres as $index => $where) {
            $whereInValues = $whereInRequests->get($index)?->first() ?? [];

            if (($where['omit'] ?? 'false') === 'true') {
                if (count(array_keys($where)) > 1 || (count(array_keys($where)) === 1 && (collect($whereInValues)->value('omit') ?? 'false') === 'false')) {
                    $newWheres[] = $where;

                    continue;
                }
            }

            if (empty($whereInValues)) {
                $newWheres[] = $where;
            } else {
                foreach ($whereInValues as $whereInValue) {
                    $newWheres[] = array_merge($where, $whereInValue);
                }
            }
        }

        $this->wheres = $newWheres;
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return mixed
     */
    public function min($column, $direction = self::ASCEND)
    {
        $this->orderBy($column, $direction);
        $result = $this->first();
        $min = $result['fieldData'][$this->getMappedFieldName($column)];

        return $min;
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->min($column, self::DESCEND);
    }

    /**
     * Edit the record and get the raw FileMaker Data API Response
     *
     * @throws FileMakerDataApiException
     */
    public function editRecord()
    {
        $response = $this->connection->editRecord($this);

        return $response;
    }

    /**
     * Create a record and get the raw FileMaker Data API Response
     *
     * @return bool
     *
     * @throws FileMakerDataApiException
     */
    public function createRecord()
    {
        $response = $this->connection->createRecord($this);

        return $response;
    }

    /**
     * Set the field data to be used when creating or editing a record
     *
     * @param $array array
     * @return $this
     */
    public function fieldData(array $array)
    {
        $this->fieldData = $this->mapFieldNamesForArray($array);

        return $this;
    }

    /**
     * Set the portal data to be used when creating or updating a record
     *
     * @param $array array
     * @return $this
     */
    public function portalData(array $array)
    {
        $this->portalData = $array;

        return $this;
    }

    /**
     * @param  string  $column The name of the container field
     * @param  File | UploadedFile | array  $file The file to be uploaded to the container or a file and filename array ex: [$file, 'MyFile.pdf']
     * @return mixed
     */
    public function setContainer($column, $file)
    {
        $this->containerFieldName = $this->getMappedFieldName($column);
        $this->containerFile = $file;
        $response = $this->connection->uploadToContainerField($this);

        return $response;
    }

    /**
     * Insert new records into the database.
     *
     * @return bool
     */
    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }

        $this->fieldData = $this->mapFieldNamesForArray($values);

        //TODO handle inserting multiple records at once, maybe?
        //TODO handle setting portal data

        // Finally, we will run this query against the database connection and return
        // the results. We will need to also flatten these bindings before running
        // the query so they are all in one huge, flattened array for execution.
        return $this->connection->createRecord($this);
    }

    public function duplicate(int $recordId): array
    {
        $this->recordId($recordId);
        $response = $this->connection->duplicateRecord($this);

        return $response;
    }

    /**
     * Update records in the database.
     *
     * @return int
     */
    public function update(array $values)
    {
        $this->applyBeforeQueryCallbacks();

        $this->fieldData($values);

        $this->computeWhereIns();

        return $this->connection->update($this);
    }

    public function layout($layoutName)
    {
        $this->from($layoutName);

        return $this;
    }

    public function findByRecordId($recordId)
    {
        $this->recordId = $recordId;

        return $this->connection->getSingleRecordById($this);
    }

    /**
     * Set fields in $columns to = to find empty fields
     *
     * @param  string|array  $columns
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        if ($not) {
            // where NOT null
            $this->where($columns, null, '*', $boolean);
        } else {
            // where null
            $this->where($columns, null, '=', $boolean);
        }

        return $this;
    }

    protected function addFindRequest()
    {
        array_push($this->wheres, []);

        $this->setFindRequestIndex(count($this->wheres) - 1);
    }

    /**
     * Add a where between statement to the query.
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        $this->where($column, null, $values[0] . '...' . $values[1], $boolean);

        return $this;
    }

    /**
     * Set the FileMaker record modId for editing an existing record.
     * FileMaker modIds look like numbers, but must always be strings.
     *
     * @return $this
     */
    public function modId(string $modId)
    {
        $this->modId = $modId;

        return $this;
    }

    /**
     * The name of a portal or an array of portals to return with the record data
     *
     * @return $this
     */
    public function portal($portalName)
    {
        if (is_array($portalName)) {
            // it's an array, so set this as the value
            $this->portal = $portalName;
        } else {
            // It's a single value, so append it on the array
            array_push($this->portal, $portalName);
        }

        return $this;
    }

    /**
     * Alias for executeScript()
     *
     *
     * @param  null  $script
     * @param  null  $param
     * @return array|mixed
     */
    public function performScript($script = null, $param = null)
    {
        return $this->executeScript($script, $param);
    }

    /**
     * Execute a script
     *
     * @param  null  $script
     * @param  null  $param
     */
    public function executeScript($script = null, $param = null)
    {
        if ($script) {
            $this->script = $script;
        }

        if ($param) {
            $this->scriptParam = $param;
        }

        $result = $this->connection->executeScript($this);

        return $result;
    }

    public function getLayoutMetadata($layoutName = null)
    {
        if ($layoutName) {
            $this->layout($layoutName);
        }

        return $this->connection->getLayoutMetadata($this);
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     * @param  string  $value
     * @param  string  $operator
     * @param  bool  $useDefault
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    public function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, ''];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    public function setGlobalFields(array $globals)
    {
        $this->globalFields = $globals;

        return $this->connection->setGlobalFields($globals);
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param  string  $columns
     * @return int
     */
    public function count($columns = '*')
    {
        $response = $this->limit(1)->getData();

        return (int) (Arr::get($response, 'response.dataInfo.foundCount', 0));
    }

    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        if (is_null($value)) {
            $value = $operator;
            $operator = '=';
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('n/j/Y');
        }

        return $this->where($column, $operator, $value, $boolean);
    }

    protected function isWheresEmpty()
    {
        $wheres = collect($this->wheres);

        if ($wheres->isEmpty()) {
            return true;
        }

        return collect($wheres->first())->keys()->except(['omit'])->isEmpty();
    }

    public function setFindRequestIndex($index)
    {
        $this->currentFindRequestIndex = $index;
    }

    public function resetFindRequestIndex()
    {
        $this->currentFindRequestIndex = -1;
    }
}
