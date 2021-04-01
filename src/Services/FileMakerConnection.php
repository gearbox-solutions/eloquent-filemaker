<?php


namespace BlueFeather\EloquentFileMaker\Services;


use BlueFeather\EloquentFileMaker\Database\Eloquent\FMEloquentBuilder;
use BlueFeather\EloquentFileMaker\Database\Eloquent\FMModel;
use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FileMakerConnection extends Connection
{
    protected $protocol = 'https';
    protected $host;
    protected $layout;

    protected $username;
    protected $password;
    protected $sessionToken;


    /**
     * Create a new FileMakerConnection
     * @param string $connectionName
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     */
    public function __construct($connectionName, $database = '', $tablePrefix = '', $config = [])
    {

        // First we will setup the default properties. We keep track of the DB
        // name we are connected to since it is needed when some reflective
        // type commands are run such as checking whether a table exists.
        $this->database = $database;

        $this->tablePrefix = $tablePrefix;

        if (!$config) {
            $this->setConnection($connectionName);
        } else {
            $this->config = $config;
            $this->config['name'] = $connectionName;
        }
    }

    /**
     * Set the name of the connected database.
     *
     * @param string $connection
     * @return $this
     */
    public function setConnection($connection)
    {
        $this->config = $this->getConnection($connection);
        $this->config['name'] = $connection;

        return $this;
    }

    protected function getConnection($connection)
    {
        return config('database.connections')[$connection];
    }

    /**
     * @param String $layout
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
     * @return mixed
     */
    protected function fetchNewSessionToken()
    {
        $url = $this->getDatabaseUrl() . '/sessions';

        // prepare the post body
        $postBody = [
            'fmDataSource' => [Arr::only($this->config, ['database', 'username', 'password'])
            ]
        ];

        // perform the login
        $response = Http::withBasicAuth($this->config['username'], $this->config['password'])
            ->post($url, $postBody);
        // Check for errors
        $this->checkResponseForErrors($response);

        // Get the session token from the response
        $token = $response['response']['token'];

        // alternative - don't provide a body at all
        /* $response = Http::withBasicAuth($this->username, $this->password)
                    ->withBody(null, 'application/json')
                    ->post($url);
        */

        return $token;
    }

    protected function getDatabaseUrl()
    {
        return ($this->config['protocol'] ?? 'https') . "://" . $this->config['host'] . '/fmi/data/' . ($this->config['version'] ?? 'vLatest') . '/databases/' . $this->config['database'];
    }

    /**
     * @param $response
     * @throws FileMakerDataApiException
     */
    protected function checkResponseForErrors($response)
    {
        $messages = $response['messages'];

        foreach ($messages as $message) {
            $code = $message['code'];

            if ($code != 0) {
                switch ($code) {
                    case 952:
                        // API token is expired. We should expire it in the cache so it isn't used again.
                        $this->forgetSessionToken();
                    default:
                        throw new FileMakerDataApiException($message['message'], $code);
                }
            }
        }
    }

    protected function getRecordUrl()
    {
        return $this->getLayoutUrl() . '/records/';
    }

    protected function getLayoutUrl()
    {
        return $this->getDatabaseUrl() . '/layouts/' . $this->getLayout();
    }

    protected function prepareFieldDataForFileMaker(FMModel $model)
    {

        $fieldData = collect($model->toArray());
        $fieldMapping = $model->getFieldMapping();

        // Only try field mapping if the user has specified a mapping
        // otherwise map directly
        if (!empty($fieldMapping)) {
            // Translate Laravel model fields into FileMaker fields using the field mapping
            $fieldData = collect($fieldMapping)->mapWithKeys(function ($key, $value) use ($fieldData, $fieldMapping) {
                return [$value => $fieldData[$key] ?? null];
            });
        }

        // Remove any fields which have been marked as read-only so we don't try to write and cause an error
        $fieldData->forget($model->getReadOnlyFields());

        // Remove any container fields
        $fieldData->forget($model->getContainerFields());
        return $fieldData;
    }


    public function uploadToContainerField(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl() . $query->getRecordId() . '/containers/' . $query->containerFieldName;

        $file = $query->containerFile;

        // create a stream resource
        $stream = fopen($file->getPathname(), 'r');
        $response = Http::withToken($this->sessionToken)
            ->attach('upload', $stream, $file->getFilename())
            ->post($url)
            ->json();
        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }


    public function getSingleRecordById(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl() . $query->getRecordId();

        $response = Http::withToken($this->sessionToken)
            ->get($url)
            ->json();
        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;

    }

    public function deleteRecord(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl() . $query->getRecordId();

        $response = Http::withToken($this->sessionToken)
            ->delete($url)
            ->json();
        // Check for errors
        $this->checkResponseForErrors($response);

        return true;
    }

    public function duplicateRecord(FMBaseBuilder $query): array
    {
        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl() . $query->getRecordId();

        // duplicate the record
        $response = Http::withToken($this->sessionToken)
            ->withBody(null, 'application/json')
            ->post($url)
            ->json();
        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    /**
     * @param FMEloquentBuilder $query
     * @return mixed
     * @throws FileMakerDataApiException
     */
    public function performFind(FMBaseBuilder $query)
    {
        // if there are no query parameters we need to do a get all records instead of a find
        if (empty($query->wheres)) {
            return $this->getRecords($query);
        }

        // There are actually query parameters, so prepare to do our find
        $this->login();
        $this->setLayout($query->from);
        $url = $this->getLayoutUrl() . '/_find';

        $postData = $this->buildPostDataFromQuery($query);


        $response = Http::withToken($this->sessionToken)
            ->post($url, $postData)
            ->json();

        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    public function getRecords(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl();

        // default to an empty array
        $queryParameters = [];

        // handle scripts
        if ($query->script !== null) {
            $queryParameters['script'] = $query->script;
        }
        if ($query->scriptParam !== null) {
            $queryParameters['script.param'] = $query->scriptParam;
        }
        if ($query->scriptPresort !== null) {
            $queryParameters['script.presort'] = $query->scriptPresort;
        }
        if ($query->scriptPresortParam !== null) {
            $queryParameters['script.presort.param'] = $query->scriptPresortParam;
        }
        if ($query->scriptPrerequest !== null) {
            $queryParameters['script.prerequest'] = $query->scriptPrerequest;
        }
        if ($query->scriptPrerequestParam !== null) {
            $queryParameters['script.prerequest.param'] = $query->scriptPrerequestParam;
        }
        if ($query->layoutResponse !== null) {
            $queryParameters['layout.response'] = $query->layoutResponse;
        }
        if ($query->portal !== null) {
            $queryParameters['portal'] = $query->portal;
        }
        if ($query->offset > 0) {
            // Offset is 1-indexed
            $queryParameters['_offset'] = $query->offset + 1;
        }
        if ($query->limit > 0) {
            $queryParameters['_limit'] = $query->limit;
        }
        if (sizeof($query->sorts) > 0) {
            // sort can have many values, so it needs to get json_encoded and passed as a single string
            $queryParameters['_sort'] = json_encode($query->sorts);
        }


        $response = Http::withToken($this->sessionToken)->get($url, $queryParameters)->json();
        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    /**
     * Get a new query builder instance.
     *
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
     * @param FMBaseBuilder $query
     */
    public function editRecord($query)
    {
        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl() . $query->getRecordId();


        // prepare all the post data
        $postData = $this->buildPostDataFromQuery($query);

        $response = Http::withToken($this->sessionToken)
            ->patch($url, $postData)
            ->json();
        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    /**
     * @param FMBaseBuilder $query
     * @return bool
     * @throws FileMakerDataApiException
     */
    public function createRecord($query)
    {

        $this->setLayout($query->from);
        $this->login();
        $url = $this->getRecordUrl();


        // prepare all the post data
        $postData = $this->buildPostDataFromQuery($query);

        $response = Http::withToken($this->sessionToken)
            ->post($url, $postData)
            ->json();

        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    protected function buildPostDataFromQuery(FMBaseBuilder $query)
    {
        $postData = [];

        // only set field data if it exists
        if ($query->fieldData) {
            // fieldData needs to have a value if it's there, but an empty object is ok instead of null
            $postData['fieldData'] = $query->fieldData ?? json_decode("{}");
        }

        // attribute => parameter
        $params = [
            'wheres' => 'query',
            'sorts' => 'sort',
            'script' => 'script',
            'scriptParam' => 'script.param',
            'scriptPrerequest' => 'script.prerequest',
            'scriptPrerequestParam' => 'script.prerequets.param',
            'scriptPresort' => 'script.presort',
            'scriptPresortParam' => 'script.presort.param',
            'offset' => 'offset',
            'limit' => 'limit',
            'layoutResponse' => 'layout.response',
            'portal' => 'portal',
            'modId' => 'modId',
            'portalData' => 'portalData'
        ];

        foreach ($params as $attribute => $request) {
            $value = $query->$attribute;

            // just skip  everything else if the value is null
            if ($value === null) {
                continue;
            }

            if (is_array($value) && sizeof($value) == 0) {
                continue;
            }

            // add it to the postdata if it's not null
            if ($value !== null) {
                $postData[$request] = $value;
            }
        }

        // Special handling for offset
        if (isset($postData['offset'])) {
            if ($postData['offset'] > 0) {
                // increment offset if it's set, since offset is 1-indexed
                $postData['offset']++;
            } else if ($postData['offset'] == 0) {
                // We shouldn't have  an offset of 0 for an API call, so remove the key if something set the offset to 0
                unset($postData['offset']);
            }
        }

        return $postData;
    }

    public function executeScript(FMBaseBuilder $query)
    {

        $this->setLayout($query->from);
        $this->login();
        $url = $this->getLayoutUrl() . "/script/" . $query->script;


        $queryParams = [];
        $param = $query->scriptParam;
        if ($param !== null) {
            $queryParams['script.param'] = $param;
        }

        $response = Http::withToken($this->sessionToken)
            ->get($url, $queryParams)
            ->json();

        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }

    /**
     * Alias for executeScript()
     *
     * @param FMBaseBuilder $query
     * @return array|mixed
     */
    public function performScript(FMBaseBuilder $query){
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
     * @throws FileMakerDataApiException
     */
    public function disconnect()
    {
        $this->login();
        $url = $this->getDatabaseUrl() . "/sessions/" . $this->sessionToken;

        $response = Http::withToken($this->sessionToken)
            ->delete($url)
            ->json();

        // Check for errors
        $this->checkResponseForErrors($response);

        $this->forgetSessionToken();

        return $response;
    }

    /**
     * Remove the session token from the cache
     *
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

        $this->login();
        $url = $this->getDatabaseUrl() . "/globals/";


        // Prepare the data to send
        $data = [
            'globalFields' => $globalFields
        ];


        $response = Http::withToken($this->sessionToken)
            ->patch($url, $data)
            ->json();

        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
    }
}
