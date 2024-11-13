<?php

namespace GearboxSolutions\EloquentFileMaker\Services;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMEloquentBuilder;
use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Database\Query\Grammars\FMGrammar;
use GearboxSolutions\EloquentFileMaker\Database\Schema\FMBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\File;

class FileMakerConnection extends Connection
{
    protected string $protocol = 'https';

    protected ?string $host;

    protected ?string $layout;

    protected ?string $username;

    protected ?string $password;

    protected ?string $sessionToken = null;

    protected int $attempts = 2;

    protected bool $shouldCacheSessionToken = true;

    protected ?string $sessionTokenCacheKey = null;

    protected bool $emptyStringToNull = true;

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {

        $this->emptyStringToNull = $config['empty_strings_to_null'] ?? true;
        $this->shouldCacheSessionToken = $config['cache_session_token'] ?? true;

        // set the session cache key with the name of the connection to support multiple connections
        $this->sessionTokenCacheKey = 'eloquent-filemaker-session-token-' . $config['name'];

        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    /**
     * Crazy high number of records to return.
     * Used to get an empty set when using a whereIn with no values.
     */
    public const CRAZY_RECORDS_AMOUNT = 1000000000000000000;

    /**
     * @param  string  $layout
     * @return $this
     */
    public function setLayout($layout)
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * @return string
     */
    public function getLayout()
    {
        return $this->tablePrefix . $this->layout;
    }

    public function login()
    {
        // return early if we're already logged in
        if ($this->sessionToken) {
            return;
        }

        // retrieve and store the session token
        // Store it in the cache if the connection is configured to do so
        if ($this->shouldCacheSessionToken) {
            $this->sessionToken = Cache::rememberForever($this->sessionTokenCacheKey, function () {
                return $this->fetchNewSessionToken();
            });
        } else {
            // we're not going to reuse it, so just get a new one
            $this->sessionToken = $this->fetchNewSessionToken();
        }
    }

    /**
     * Log in and get a session token to use with subsequent DataAPI requests which require authentication
     *
     * @return mixed
     */
    protected function fetchNewSessionToken()
    {
        $start = microtime(true);

        $url = $this->getDatabaseUrl() . '/sessions';

        // prepare the post body
        $postBody = [
            'fmDataSource' => [
                Arr::only($this->config, ['database', 'username', 'password']),
            ],
        ];

        // perform the login
        try {
            $response = Http::retry($this->attempts, 100)->withBasicAuth($this->config['username'], $this->config['password'])
                ->post($url, $postBody);
        } catch (\Exception $e) {
            // log the query even on an error
            $this->logFMQuery('post', $url, $postBody, $start);
            throw $e;
        }

        // log the query
        $this->logFMQuery('post', $url, $postBody, $start);

        // Check for errors
        $this->checkResponseForErrors($response);

        Arr::set($postBody, 'fmDataSource.0.username', str_repeat('*', strlen(Arr::get($postBody, 'fmDataSource.0.username'))));
        Arr::set($postBody, 'fmDataSource.0.password', str_repeat('*', strlen(Arr::get($postBody, 'fmDataSource.0.password'))));

        // Get the session token from the response
        $token = Arr::get($response, 'response.token');

        return $token;
    }

    protected function getDatabaseUrl()
    {
        return ($this->config['protocol'] ?? 'https') . '://' . $this->config['host'] . '/fmi/data/' . ($this->config['version'] ?? 'vLatest') . '/databases/' . $this->config['database'];
    }

    protected function getRecordUrl()
    {
        return $this->getLayoutUrl() . '/records/';
    }

    protected function getLayoutUrl($layout = null)
    {
        // Set the connection layout as the layout parameter, otherwise get the layout from the connection
        if ($layout) {
            $this->setLayout($layout);
        }

        return $this->getDatabaseUrl() . '/layouts/' . $this->getLayout();
    }

    /**
     * @throws FileMakerDataApiException
     */
    protected function checkResponseForErrors($response): void
    {
        $messages = Arr::get($response, 'messages', []);

        if ($messages) {
            foreach ($messages as $message) {
                $code = (int) $message['code'];

                if ($code !== 0) {

                    // If the layout is not the same as the table prefix, a layout has been specified and we
                    // should to add the layout name for clarity
                    if ($this->layout) {
                        $customMessage = 'Layout: ' . $this->getLayout() . ' - ' . $message['message'];
                    } else {
                        $customMessage = $message['message'];
                    }
                    throw new FileMakerDataApiException($customMessage, $code);
                }
            }
        } else {
            $response->throw();
        }
    }

    public function uploadToContainerField(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl() . $query->getRecordId() . '/containers/' . $query->containerFieldName;

        /*
         * The user can insert an array for the file to specify the file name, so we can check for that here
         * The array should be in the format:
         * [ $file, 'myFile.pdf' ]
         */
        if (is_array($query->containerFile)) {
            // we have a file and file name
            $file = $query->containerFile[0];
            $filename = $query->containerFile[1];
        } else {
            $file = $query->containerFile;
            $filename = $file->getFilename();
        }

        // create a stream resource
        $stream = fopen($file->getPath() . '/' . $file->getFilename(), 'r');

        $request = Http::attach('upload', $stream, $filename);
        $response = $this->makeRequest('post', $url, [], $request);

        return $response;
    }

    public function getSingleRecordById(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl() . $query->getRecordId();

        $response = $this->makeRequest('get', $url);

        return $response;
    }

    public function deleteRecord(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl() . $query->getRecordId();

        $response = $this->makeRequest('delete', $url);

        return $response;
    }

    public function duplicateRecord(FMBaseBuilder $query): array
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl() . $query->getRecordId();

        // duplicate the record
        $request = Http::withBody(null, 'application/json');
        $response = $this->makeRequest('post', $url, [], $request);

        return $response;
    }

    /**
     * @param  FMEloquentBuilder  $query
     * @return mixed
     *
     * @throws FileMakerDataApiException
     */
    public function performFind(FMBaseBuilder $query)
    {
        // If limit hasn't been specified we should set it to be very high to bypass FM's default 100-record limit
        // This more closely matches Laravel's default behavior
        if (! isset($query->limit)) {
            $query->limit = self::CRAZY_RECORDS_AMOUNT;
        }

        // remove any empty arrays from wheres
        // an empty find is invalid
        $query->wheres = collect($query->wheres)->filter(function ($item) {
            return is_array($item) ? count($item) > 0 : true;
        })->toArray();

        // if there are no query parameters we need to do a get all records instead of a find
        if (empty($query->wheres) && ! $query->isForcingHighOffset()) {
            return $this->getRecords($query);
        }

        // Update the offset to a crazy high offset when the query is forcing 0 records to be returned
        // The records call requires that at least 1
        $query->offset($query->isForcingHighOffset() ? self::CRAZY_RECORDS_AMOUNT : $query->offset);

        // There are actually query parameters, so prepare to do our find
        $this->setLayout($query->from);
        $url = $this->getLayoutUrl() . '/_find';

        $postData = $this->buildPostDataFromQuery($query);

        $response = $this->makeRequest('post', $url, $postData);

        return $response;
    }

    public function getRecords(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl();

        // default to an empty array
        $queryParams = [];

        // handle single record requests
        if ($query->getRecordId() !== null) {
            $url .= (Str::endsWith($url, '/') ? '' : '/') . $query->getRecordId();
        } else {
            // handle pagination and sorting
            // these parameters are not used for single record requests
            if ($query->offset > 0) {
                // Offset is 1-indexed
                $queryParams['_offset'] = $query->offset + 1;
            }
            if ($query->limit > 0) {
                $queryParams['_limit'] = $query->limit;
            }
            if ($query->orders !== null && count($query->orders) > 0) {
                // sort can have many values, so it needs to get json_encoded and passed as a single string
                $queryParams['_sort'] = json_encode($query->orders);
            }
        }

        // handle scripts
        if ($query->script !== null) {
            $queryParams['script'] = $query->script;
        }
        if ($query->scriptParam !== null) {
            $queryParams['script.param'] = $query->scriptParam;
        }
        if ($query->scriptPresort !== null) {
            $queryParams['script.presort'] = $query->scriptPresort;
        }
        if ($query->scriptPresortParam !== null) {
            $queryParams['script.presort.param'] = $query->scriptPresortParam;
        }
        if ($query->scriptPrerequest !== null) {
            $queryParams['script.prerequest'] = $query->scriptPrerequest;
        }
        if ($query->scriptPrerequestParam !== null) {
            $queryParams['script.prerequest.param'] = $query->scriptPrerequestParam;
        }

        if ($query->layoutResponse !== null) {
            $queryParams['layout.response'] = $query->layoutResponse;
        }
        if ($query->portal !== null) {
            $queryParams['portal'] = $query->portal;
        }

        $response = $this->makeRequest('get', $url, $queryParams);

        return $response;
    }

    /**
     * Get a new query builder instance.
     */
    public function query()
    {
        return new FMBaseBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Edit a record in FileMaker
     *
     *
     * @throws FileMakerDataApiException
     */
    public function editRecord(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl() . $query->getRecordId();

        // prepare all the post data
        $postData = $this->buildPostDataFromQuery($query);

        $response = $this->makeRequest('patch', $url, $postData);

        return $response;
    }

    /**
     * Attempt to emulate a sql update in FileMaker
     *
     * @param  FMBaseBuilder  $query
     * @param  array  $bindings
     * @return int
     */
    public function update($query, $bindings = [])
    {
        // If there's no FM Record ID it means we need to find a set of records and then update the results
        if (! $query->getRecordId()) {
            // There's no FileMaker Record ID
            return $this->performFindAndUpdateResults($query);
        }

        // This is just a single record to edit
        try {
            // Do the update

            // Insert container data before updating text fields since scripts can be attached to the regular record edit

            // Only attempt to write modified container fields
            // Figure out which fields are containers vs non-containers
            $textDataFields = $this->getNonContainerFieldsForRecordWrite($query->fieldData) ?? [];
            $modifiedContainerFields = collect($query->fieldData)->diffKeys($textDataFields);
            foreach ($modifiedContainerFields as $containerField => $fileData) {
                $eachResponse = $query->recordId($query->getRecordId())->setContainer($containerField, $fileData);
                $query->modId($this->getModIdFromFmResponse($eachResponse));
            }

            // only do an edit record if there are non-container fields to edit
            // It's technically valid in the FileMaker Data API to call edit with no fields to modify to create a
            // record, but that doesn't really match Laravel's behavior. Users who want to do this should
            // call edit() directly.
            if ($textDataFields->count() > 0) {
                $this->editRecord($query);
            }
        } catch (FileMakerDataApiException $e) {
            // Record is missing is ok for laravel functions
            // Throw if it isn't error code 101, which is missing record
            if ($e->getCode() !== 101) {
                throw $e;
            }

            // Error 101 - Record Not Found
            // we didn't end up updating any records
            return 0;
        }

        // one record has been edited
        return 1;
    }

    protected function getModIdFromFmResponse($response)
    {
        return $response['response']['modId'];
    }

    /**
     * @return int
     *
     * @throws FileMakerDataApiException
     */
    protected function performFindAndUpdateResults(FMBaseBuilder $query)
    {
        // find the records in the find request query
        $findQuery = clone $query;
        $findQuery->fieldData = null;
        try {
            $results = $this->performFind($findQuery);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() === 401) {
                // no records match request
                // we should exit here
                // return 0 to show that no records were updated;
                return 0;
            }
        }

        $records = $results['response']['data'];
        $updatedCount = 0;
        foreach ($records as $record) {
            // update each record
            $builder = new FMBaseBuilder($this);
            $builder->recordId($record['recordId']);
            $builder->fieldData = $query->fieldData;
            $builder->layout($query->from);
            try {
                // Do the update
                $this->update($builder);
                // Update if we don't get a record missing exception
                $updatedCount++;
            } catch (FileMakerDataApiException $e) {
                // Record is missing is ok for laravel functions
                // Throw if it isn't error code 101, which is missing record
                if ($e->getCode() !== 101) {
                    throw $e;
                }
            }
        }

        return count($records);
    }

    /**
     * @return bool
     *
     * @throws FileMakerDataApiException
     */
    public function createRecord(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl();

        // prepare all the post data
        $postData = $this->buildPostDataFromQuery($query);

        // fieldData is required for create, so fill a blank value if none exists
        if (! isset($postData['fieldData'])) {
            $postData['fieldData'] = json_decode('{}');
        }

        $response = $this->makeRequest('post', $url, $postData);

        return $response;
    }

    public function buildPostDataFromQuery(FMBaseBuilder $query)
    {
        $postData = [];

        // only set field data if it exists
        if ($query->fieldData) {
            // fieldData needs to have a value if it's there, but an empty object is ok instead of null
            $postData['fieldData'] = $this->getNonContainerFieldsForRecordWrite($query->fieldData) ?? json_decode('{}');
        }

        // attribute => parameter
        $params = [
            'wheres' => 'query',
            'orders' => 'sort',
            'script' => 'script',
            'scriptParam' => 'script.param',
            'scriptPrerequest' => 'script.prerequest',
            'scriptPrerequestParam' => 'script.prerequest.param',
            'scriptPresort' => 'script.presort',
            'scriptPresortParam' => 'script.presort.param',
            'offset' => 'offset',
            'limit' => 'limit',
            'layoutResponse' => 'layout.response',
            'portal' => 'portal',
            'modId' => 'modId',
            'portalData' => 'portalData',
        ];

        foreach ($params as $attribute => $request) {
            $value = $query->$attribute;

            // just skip everything else if the value is null
            if ($value === null) {
                continue;
            }

            if (is_array($value) && count($value) == 0) {
                continue;
            }

            // add it to the postdata if it's not null
            if ($value !== null) {
                $postData[$request] = $value;
            }
        }

        // Special handling for _limit.{portal}
        if (isset($query->limitPortals) && count($query->limitPortals) > 0) {
            foreach ($query->limitPortals as $portalArray) {
                $postData['limit.' . urlencode($portalArray['portalName'])] = $portalArray['limit'];
            }
        }

        // handle _offset.{portal}
        if (isset($query->offsetPortals) && count($query->offsetPortals) > 0) {
            foreach ($query->offsetPortals as $portalArray) {
                $postData['offset.' . urlencode($portalArray['portalName'])] = $portalArray['offset'];
            }
        }

        // Special handling for offset
        if (isset($postData['offset'])) {
            if ($postData['offset'] > 0) {
                // increment offset if it's set, since offset is 1-indexed
                $postData['offset']++;
            } elseif ($postData['offset'] == 0) {
                // We shouldn't have  an offset of 0 for an API call, so remove the key if something set the offset to 0
                unset($postData['offset']);
            }
        }

        return $postData;
    }

    /**
     * Strip out containers and read-only fields, convert null values to empty strings to prepare for a write query
     *
     * @return Collection
     */
    protected function getNonContainerFieldsForRecordWrite($fieldArray)
    {
        $fieldData = collect($fieldArray);

        // Remove any fields which have been set to write a file, as they should be handled as containers
        foreach ($fieldData as $key => $field) {
            // remove any containers to be written.
            // users can set the field to be a File, UploadFile, or array [$file, 'MyFile.pdf']
            if ($this->isContainer($field)) {
                $fieldData->forget($key);
            }

            // set any null value to an empty string - FileMaker doesn't use true null values
            if ($field === null) {
                $fieldData->put($key, '');
            }
        }

        return $fieldData;
    }

    protected function isContainer($field)
    {
        // if this is a file then we know it's a container
        if (is_a($field, File::class)) {
            return true;
        }

        // if it's an array, it could be a file => filename key-value pair.
        // it's a container if the first object in the array is a file
        if (is_array($field) && count($field) === 2 && $this->isFile($field[0])) {
            return true;
        }

        return false;
    }

    protected function isFile($object)
    {
        return is_a($object, \Illuminate\Http\File::class) ||
            is_a($object, UploadedFile::class);
    }

    public function executeScript(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getLayoutUrl() . '/script/' . $query->script;

        $queryParams = [];
        $param = $query->scriptParam;
        if ($param !== null) {
            $queryParams['script.param'] = $param;
        }

        $response = $this->makeRequest('get', $url, $queryParams);

        return $response;
    }

    /**
     * Alias for executeScript()
     *
     * @return array|mixed
     */
    public function performScript(FMBaseBuilder $query)
    {
        return $this->executeScript($query);
    }

    /**
     * Log out of the database, invalidating our session token
     *
     * @return \Illuminate\Http\Client\Response|void
     *
     * @throws FileMakerDataApiException
     */
    public function disconnect()
    {
        if (! $this->sessionToken) {
            return;
        }

        $url = $this->getDatabaseUrl() . '/sessions/' . $this->sessionToken;

        // make an http delete request to the data api to end the session
        $response = Http::delete($url);
        $this->checkResponseForErrors($response);

        $this->forgetSessionToken();

        return $response;
    }

    /**
     * Remove the session token from the cache
     */
    public function forgetSessionToken()
    {
        $this->sessionToken = null;

        // clear the token from the cache if we're configured to store it
        if ($this->shouldCacheSessionToken) {
            Cache::forget($this->sessionTokenCacheKey);
        }
    }

    public function layout($layoutName)
    {
        return $this->table($layoutName);
    }

    public function setGlobalFields(array $globalFields)
    {
        $url = $this->getDatabaseUrl() . '/globals/';

        // Prepare the data to send
        $data = [
            'globalFields' => $globalFields,
        ];

        $response = $this->makeRequest('patch', $url, $data);

        return $response;
    }

    protected function prepareRequestForSending($request = null)
    {
        if (! $request) {
            if (method_exists(Factory::class, 'createPendingRequest')) {
                $request = Http::createPendingRequest();
            } else {
                $request = Http::acceptJson();
            }
        }

        $request->retry($this->attempts, 100, fn () => true, false)->withToken($this->sessionToken);

        return $request;
    }

    /**
     * @throws FileMakerDataApiException
     */
    protected function makeRequest($method, $url, $params = [], ?PendingRequest $request = null)
    {
        $start = microtime(true);

        $this->login();

        $request = $this->prepareRequestForSending($request);

        // make the request

        try {
            $response = $request->{$method}($url, $params);
        } catch (\Exception $e) {
            // log the query before throwing the exception
            $this->logFMQuery($method, $url, $params, $start);
            throw $e;
        }
        // log the query after a successful request
        $this->logFMQuery($method, $url, $params, $start);

        // Check for errors
        try {
            $this->checkResponseForErrors($response);
        } catch (FileMakerDataApiException $e) {
            if ($e->getCode() === 952) {
                // the session expired, so we should forget the token and re-login
                $this->forgetSessionToken();
                $this->login();

                // try the request again with refreshed credentials
                $request = $this->prepareRequestForSending($request);
                try {
                    // execute the request
                    $response = $request->{$method}($url, $params);
                } catch (\Exception $e) {
                    // log the query before throwing the exception
                    $this->logFMQuery($method, $url, $params, $start);
                    throw $e;
                }

                // log the query after a successful request
                $this->logFMQuery($method, $url, $params, $start);

                // check for errors a second time, but this time we won't catch the error if there's
                // still an auth problem
                $this->checkResponseForErrors($response);

            } else {
                throw $e;
            }
        }

        // Return the JSON response
        $json = $response->json();

        return $json;
    }

    protected function logFMQuery($method, $url, $params, $start)
    {
        $commandType = $this->getSqlCommandType($method, $url);

        // Clockwork specifically looks for the commandType as the first word in the "sql" string
        $sql = <<<DOC
                {$commandType}
                Method: {$method}
                URL: {$url}
                DOC;

        if (count($params) > 0) {
            $sql .= "\nData: " . json_encode($params, JSON_PRETTY_PRINT);
        }

        $bindings = collect(data_get($params, 'query', []))->flatMap(
            fn (array $binding, $index) => collect($binding)->mapWithKeys(fn ($value, $key) => [$index . ': ' . $key => $value])
        )->all();

        $this->event(new QueryExecuted(
            $sql,
            $bindings,
            $this->getElapsedTime($start),
            $this,
        ));
    }

    protected function getSqlCommandType($method, $url)
    {
        if ($method === 'delete') {
            return 'delete';
        }

        if ($method === 'patch') {
            return 'update';
        }

        if ($method === 'get') {
            if (Str::contains($url, 'script')) {
                return 'exec';
            }

            return 'select';
        }

        if ($method === 'post') {
            if (Str::contains($url, 'containers')) {
                return 'update';
            }

            if (Str::contains($url, '_find')) {
                return 'select';
            }

            return 'insert';
        }

        return 'other';
    }

    public function setRetries($retries)
    {
        $this->attempts = $retries + 1;

        return $this;
    }

    protected function getDefaultQueryGrammar()
    {
        return new FMGrammar;
    }

    //    public function getLayoutMetadata($layout = null)
    //    {
    //        $response = $this->makeRequest('get', $this->getLayoutUrl($layout));
    //        return $response['response'];
    //    }

    /**
     * @return mixed
     *
     * @throws FileMakerDataApiException
     */
    public function getLayoutMetadata(FMBaseBuilder|string|null $query = null)
    {
        // if the query is just a string, it means that it's a layout name
        if (is_string($query)) {
            $query = $this->table($query);
        }

        $this->setLayout($query->from);
        $url = $this->getLayoutUrl();

        $queryParams = [];
        $param = $query->getRecordId();
        if ($param !== null) {
            $queryParams['recordId'] = $param;
        }

        $response = $this->makeRequest('get', $url, $queryParams);

        return $response;
    }

    public function getSchemaBuilder()
    {
        parent::getSchemaBuilder();

        return new FMBuilder($this);
    }

    public function getPdo()
    {
        return null;
    }
}
