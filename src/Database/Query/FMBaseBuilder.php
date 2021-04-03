<?php


namespace BlueFeather\EloquentFileMaker\Database\Query;


use BlueFeather\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use BlueFeather\EloquentFileMaker\Services\FileMakerConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FMBaseBuilder extends Builder
{

    /**
     * The database connection instance.
     *
     * @var FileMakerConnection
     */
    public $connection;

    /**
     * The internal FileMaker Record ID to act on
     * @var
     */
    protected $recordId;

    /**
     * An array of fields to map to FileMaker fields
     * @var array
     */
    protected $fieldMapping = [];

    /**
     * The collection of sort orders for the query
     *
     * @var array
     */
    public $sorts = [];


    /**
     * The maximum number of records to return.
     * Default is 100 for the FileMaker data API
     *
     * @var int
     */
    public $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    public $offset;

    /**
     * The name of the FileMaker script to be run after the action specified by the API call and after the subsequent sort.
     *
     * @var string
     */
    public $script;

    /**
     * The text string to use as a parameter for the script that was named by script.
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
     * @var array
     */
    public $globalFields = [];


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
    public $portal;

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
        '=','==', '≠', '!', '<', '>', '<=', '≤', '>=', '≥', '~',
    ];


    public $containerFieldName;
    public $containerFile;


    /**
     * Create a new query builder instance.
     *
     * @param FileMakerConnection $connection
     * @param \Illuminate\Database\Query\Grammars\Grammar|null $grammar
     * @param \Illuminate\Database\Query\Processors\Processor|null $processor
     * @return void
     */
    public function __construct(FileMakerConnection $connection,
                                Grammar $grammar = null,
                                Processor $processor = null)
    {
        $this->connection = $connection;
    }


    /**
     * Add a basic where clause to the query.
     *
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): FMBaseBuilder
    {

        // This is an "orWhere" type query, so add a find request and then work from there
        if ($boolean === 'or'){
            $this->addFindRequest();
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

        // we should add this where clause as an AND to the current find request
        // This allows us to chain wheres
        // Create a new find array if null
        $count = sizeof($this->wheres);
        if ($count == 0) {
            $currentFind = collect([]);
        } else {
            $currentFind = $this->wheres[sizeof($this->wheres) - 1];
        }

        $currentFind[$this->getMappedFieldName($column)] = $operator . $value;

        $this->wheres[$count > 1 ? $count - 1 : 0] = $currentFind;

        return $this;
    }

    // convenience method for getting the first result of a find
    public function first($columns = ['*'])
    {
        // set the limit so we don't query more than we need to
        $result = $this->limit(1)->get()->first();
        return $result;
    }

    /**
     * Delete records from the database.
     *
     * @return int
     */
    public function delete($recordId = null)
    {
        $this->recordId($recordId ?? $this->getRecordId());

        return $this->connection->deleteRecord($this);
    }

    /**
     * Returns the internal FileMaker record ID returned in a previous query, used for things like edits and deletes. This is not the primary key in your database.
     * @return mixed
     */
    public function getRecordId()
    {
        return $this->recordId;
    }

    /**
     * @param mixed $recordId
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
     * @param $column
     * @param string $direction
     * @return $this
     */
    public function sort($column, $direction = self::ASCEND){
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
        array_push($this->sorts, ['fieldName' => $this->getMappedFieldName($column), 'sortOrder' => $direction]);
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param int $value
     * @return $this
     */
    public function limit($value): FMBaseBuilder
    {
        $this->limit = $value;
        return $this;
    }


    public function offset($offset): FMBaseBuilder
    {
        $this->offset = $offset;
        return $this;
    }

    public function script($scriptName): FMBaseBuilder
    {
        $this->script = $scriptName;
        return $this;
    }

    public function scriptParam($param): FMBaseBuilder
    {
        $this->scriptParam = $param;
        return $this;
    }

    public function scriptPresort($name): FMBaseBuilder
    {
        $this->scriptPresort = $name;
        return $this;
    }

    public function scriptPresortParam($param): FMBaseBuilder
    {
        $this->scriptPresortParam = $param;
        return $this;
    }

    public function scriptPrerequest($name): FMBaseBuilder
    {
        $this->scriptPrerequest = $name;
        return $this;
    }

    public function scriptPrerequestParam($param): FMBaseBuilder
    {
        $this->scriptPrerequestParam = $param;
        return $this;
    }

    public function layoutResponse($name): FMBaseBuilder
    {
        $this->layoutResponse = $name;
        return $this;
    }

    /**
     * @return Collection
     * @throws FileMakerDataApiException
     */
    public function get($columns = ['*'])
    {
        // Run the query and catch a 401 error if there are no records found - just return an empty collection
        try {
            $response = $this->connection->performFind($this);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() == 401) {
                return collect([]);
            } else {
                throw $e;
            }
        }
        $records = collect($response['response']['data']);
        return $records;
    }

    /**
     * Get the database connection instance.
     *
     * @return FileMakerConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Gets the gets the name of the mapped FileMaker field for a particular column
     *
     * @param $column
     * @return mixed
     */
    protected function getMappedFieldName($column)
    {
        // remap the field name if the dev specified a mapping
        return array_flip($this->getFieldMapping())[$column] ?? $column;
    }

    /**
     * A helper function to map an entire array of fields and data to their FileMaker field names
     *
     * @param $array array An array of columns and their values
     * @return array
     */
    protected function mapFieldNamesForArray($array)
    {

        $mappedArray = [];
        foreach ($array as $column => $value) {
            $mappedArray[$this->getMappedFieldName($column)] = $value;
        }

        return $mappedArray;
    }

    /**
     * @return array
     */
    public function getFieldMapping(): array
    {
        return $this->fieldMapping;
    }

    /**
     * @param array $fieldMapping
     */
    public function setFieldMapping(array $fieldMapping): void
    {
        $this->fieldMapping = $fieldMapping;
    }

    /**
     * Sets the current find request as an omit.
     * Optionally may pass false as a parameter to make a request NOT an omit if it was already set
     *
     */
    public function omit($boolean = true)
    {

        $count = sizeof($this->wheres);
        if ($count == 0) {
            $currentFind = collect([]);
        } else {
            $currentFind = $this->wheres[sizeof($this->wheres) - 1];
        }

        if ($boolean = false) {
            // the user is removing an omit which was already set
            $currentFind->forget('omit');
        } else {
            // add the omit flag to the current find request
            // we have to set it to the word 'true'
            $currentFind->put('omit', 'true');
        }

        $this->wheres[$count] = $currentFind;

        return $this;
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param string $column
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
     * @param string $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->min($column, self::DESCEND);
    }

    public function editRecord()
    {
        $response = $this->connection->editRecord($this);
        return $response;
    }

    public function createRecord(){
        $response = $this->connection->createRecord($this);
        return $response;
    }

    /**
     * Set the field data to be used when creating or editing a record
     * @param $array array
     * @return $this
     */
    public function fieldData($array)
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
    public function portalData($array)
    {
        $this->portalData = $array;
        return $this;
    }


    public function setContainer($column, File $file)
    {
        $this->containerFieldName = $this->getMappedFieldName($column);
        $this->containerFile = $file;
        $response = $this->connection->uploadToContainerField($this);
        return $response;
    }

    /**
     * Insert new records into the database.
     *
     * @param array $values
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
     * @param string|array $columns
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        if ($not){
            // where NOT null
            $this->where($columns, null, '*', $boolean);
        } else{
            // where null
            $this->where($columns, null, '=', $boolean);
        }
        return $this;
    }



    protected function addFindRequest()
    {
        array_push($this->wheres, []);
    }

    /**
     * Add a where between statement to the query.
     *
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $this->where($column, null, $values[0] . "..." & $values[1], $boolean);
        return $this;
    }


    public function modId(int $modId)
    {
        $this->modId = $modId;
        return $this;
    }

    /**
     * The name of a portal or an array of portals to return with the record data
     *
     * @param $portalName
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
        $this->portal = $portalName;
        return $this;
    }

    /**
     * Alias for executeScript()
     *
     *
     * @param null $script
     * @param null $param
     * @return array|mixed
     */
    public function performScript($script = null, $param = null)
    {
        return $this->executeScript($script, $param);
    }

    /**
     * Execute a script
     *
     * @param null $script
     * @param null $param
     */
    public function executeScript($script = null, $param = null){
        if ($script) {
            $this->script = $script;
        }

        if ($param) {
            $this->param = $param;
        }

        $result = $this->connection->executeScript($this);

        return $result;
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

    public function setGlobalFields(array $globals){
        $this->globalFields = $globals;
        return $this->connection->setGlobalFields($this);
    }

}
