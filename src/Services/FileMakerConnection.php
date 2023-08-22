<?php

namespace GearboxSolutions\EloquentFileMaker\Services;

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\FMEloquentBuilder;
use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Database\Query\Grammars\FMGrammar;
use GearboxSolutions\EloquentFileMaker\Database\Schema\FMBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Middleware;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\File\File;

class FileMakerConnection extends Connection
{
    protected $protocol = 'https';

    protected $host;

    protected $layout;

    protected $username;

    protected $password;

    protected $sessionToken;

    protected $retries = 1;

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
        // Cache a session token so we can reuse the same thing for 14.75 minutes
        // FileMaker data API sessions expire after 15 minutes
        // 14.75 * 60 = 885 seconds
        $token = Cache::remember($this->getSessionTokenCacheKey(), 885, function () {
            return $this->fetchNewSessionToken();
        });

        // Store the session token
        $this->sessionToken = $token;
    }

    /**
     * Log in and get a session token to use with subsequent DataAPI requests which require authentication
     *
     * @return mixed
     */
    protected function fetchNewSessionToken()
    {
        $url = $this->getDatabaseUrl() . '/sessions';

        // prepare the post body
        $postBody = [
            'fmDataSource' => [Arr::only($this->config, ['database', 'username', 'password']),
            ],
        ];

        // perform the login
        $response = Http::withBasicAuth($this->config['username'], $this->config['password'])
            ->post($url, $postBody);

        // Check for errors
        $this->checkResponseForErrors($response);

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
    protected function checkResponseForErrors($response)
    {
        $messages = Arr::get($response, 'messages', []);

        if ($messages) {
            foreach ($messages as $message) {
                $code = (int) $message['code'];

                if ($code !== 0) {
                    switch ($code) {
                        case 952:
                            // API token is expired. We should expire it in the cache so it isn't used again.
                            $this->forgetSessionToken();
                            break;
                        case 105:
                            // Layout is missing error
                            // Add the layout name to the message for clarity
                            $message = $message['message'] . ': ' . $this->getLayout();
                            throw new FileMakerDataApiException($message, $code);
                        default:
                            throw new FileMakerDataApiException($message['message'], $code);
                    }
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
            $query->limit = 1000000000000000000;
        }

        // if there are no query parameters we need to do a get all records instead of a find
        if (empty($query->wheres)) {
            return $this->getRecords($query);
        }

        // There are actually query parameters, so prepare to do our find
        $this->setLayout($query->from);
        $url = $this->getLayoutUrl() . '/_find';

        $postData = $this->buildPostDataFromQuery($query);

        $response = $this->makeRequest('post', $url, $postData);

        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    public function getRecords(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl();

        // default to an empty array
        $queryParams = [];

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

    protected function buildPostDataFromQuery(FMBaseBuilder $query)
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

            // just skip  everything else if the value is null
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
     * Strip out containers and read-only fields to prepare for a write query
     * OR - do the opposite and get ONLY containers
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
        // it's a conainer if the first object in the array is a file
        if (is_array($field) && count($field) === 2 && $this->isFile($field[0])) {
            return true;
        }

        return false;
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

    protected function getSessionTokenCacheKey()
    {
        return 'filemaker-session-' . $this->getName();
    }

    /**
     * Log out of the database, invalidating our session token
     *
     * @return array|mixed|void
     *
     * @throws FileMakerDataApiException
     */
    public function disconnect()
    {
        $url = $this->getDatabaseUrl() . '/sessions/' . $this->sessionToken;

        $response = $this->makeRequest('delete', $url);

        $this->forgetSessionToken();

        return $response;
    }

    /**
     * Remove the session token from the cache
     */
    public function forgetSessionToken()
    {
        Cache::forget($this->getSessionTokenCacheKey());
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

    /**
     * @throws FileMakerDataApiException
     */
    protected function makeRequest($method, $url, $params = [], ?PendingRequest $request = null)
    {
        $this->login();

        if ($request instanceof PendingRequest) {
            $request->withToken($this->sessionToken);
        } else {
            $request = Http::withToken($this->sessionToken);
        }

        $response = $request->withMiddleware($this->retryMiddleware())
            ->{$method}($url, $params);

        // Check for errors
        $this->checkResponseForErrors($response);

        // Return the JSON response
        $json = $response->json();

        return $json;
    }

    public function setRetries($retries)
    {
        $this->retries = $retries;

        return $this;
    }

    protected function retryMiddleware()
    {
        return Middleware::retry(function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            TransferException $exception = null
        ) {
            // Limit the number of retries to 5
            if ($retries >= $this->retries) {
                return false;
            }

            $should_retry = false;
            $refresh = false;
            $log_message = null;

            // Retry connection exceptions
            if ($exception instanceof TransferException) {
                $should_retry = true;
                $log_message = 'Connection Error: ' . $exception->getMessage();
            }

            $contents = $response?->getBody()->getContents();
            $contents = json_decode($contents, true);
            if ($response && $response->getStatusCode() !== 200 && $contents !== null) {
                $code = (int) Arr::first(Arr::pluck(Arr::get($contents, 'messages'), 'code'));
                if ($code === 952 && $retries <= 1) {
                    $refresh = true;
                    $should_retry = true;
                }
            }

            if ($log_message) {
                error_log($log_message, 0);
            }

            if ($refresh) {
                error_log('Refreshing Access Token…');
                $this->login();
            }

            if ($should_retry) {
                if ($retries > 0) {
                    error_log('Retry ' . $retries . '…', 0);
                }
            }

            return $should_retry;
        }, function () {
            return 0;
        });
    }

    protected function getDefaultQueryGrammar()
    {
        return new FMGrammar();
    }

//    public function getLayoutMetadata($layout = null)
//    {
//        $response = $this->makeRequest('get', $this->getLayoutUrl($layout));
//        return $response['response'];
//    }

    /**
     * @param  string|FMBaseBuilder  $query
     * @return mixed
     *
     * @throws FileMakerDataApiException
     */
    public function getLayoutMetadata(FMBaseBuilder|string $query = null)
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
}
