<?php


namespace BlueFeather\EloquentFileMaker\Services;


use BlueFeather\EloquentFileMaker\Database\Eloquent\FMEloquentBuilder;
use BlueFeather\EloquentFileMaker\Database\Query\FMBaseBuilder;
use BlueFeather\EloquentFileMaker\Exceptions\FileMakerDataApiException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
     * Create a new FileMakerConnection
     * @param string $connectionName
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     */
    public function __construct($connectionName, $database = '', $tablePrefix = '', $config = [])
    {
        // First we will setup the default properties. We keep track of the DB connection
        // name we are connected to since it is needed when some reflective
        // type commands are run such as checking whether a table exists.
        $this->database = $connectionName;

        $this->setConnection($connectionName, $config);
    }

    /**
     * Set the name of the connected database.
     *
     * @param string $connection
     * @return $this
     */
    public function setConnection($connection, $config = [])
    {
        if (!$config) {
            $config = $this->getConnection($connection);
        }

        $this->config = Arr::add($config, 'name', $connection);

        $this->tablePrefix = $this->getConfig('prefix');

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
        $token = Arr::get($response, 'response.token');

        return $token;
    }

    protected function getDatabaseUrl()
    {
        return ($this->config['protocol'] ?? 'https') . "://" . $this->config['host'] . '/fmi/data/' . ($this->config['version'] ?? 'vLatest') . '/databases/' . $this->config['database'];
    }

    protected function getRecordUrl()
    {
        return $this->getLayoutUrl() . '/records/';
    }

    protected function getLayoutUrl()
    {
        return $this->getDatabaseUrl() . '/layouts/' . $this->getLayout();
    }

    /**
     * @param $response
     * @throws FileMakerDataApiException
     */
    protected function checkResponseForErrors($response)
    {
        $messages = Arr::get($response, 'messages', []);

        foreach ($messages as $message) {
            $code = (int)$message['code'];

            if ($code !== 0) {
                switch ($code) {
                    case 952:
                        // API token is expired. We should expire it in the cache so it isn't used again.
                        $this->forgetSessionToken();
                        return;
                    default:
                        throw new FileMakerDataApiException($message['message'], $code);
                }
            }
        }
    }


    public function uploadToContainerField(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl() . $query->getRecordId() . '/containers/' . $query->containerFieldName;

        $file = $query->containerFile;

        // create a stream resource
        $stream = fopen($file->getPathname(), 'r');

        $request = Http::attach('upload', $stream, $file->getFilename());
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
        if (sizeof($query->sorts) > 0) {
            // sort can have many values, so it needs to get json_encoded and passed as a single string
            $queryParams['_sort'] = json_encode($query->sorts);
        }

        $response = $this->makeRequest('get', $url, $queryParams);

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
     * @param FMBaseBuilder $query
     * @return bool
     * @throws FileMakerDataApiException
     */
    public function createRecord(FMBaseBuilder $query)
    {
        $this->setLayout($query->from);
        $url = $this->getRecordUrl();

        // prepare all the post data
        $postData = $this->buildPostDataFromQuery($query);

        // fieldData is required for create, so fill a blank value if none exists
        if (!isset($postData['fieldData'])){
            $postData['fieldData'] = json_decode("{}");
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
        $url = $this->getLayoutUrl() . "/script/" . $query->script;

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
     * @param FMBaseBuilder $query
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
     * @throws FileMakerDataApiException
     */
    public function disconnect()
    {
        $url = $this->getDatabaseUrl() . "/sessions/" . $this->sessionToken;

        $response = $this->makeRequest('delete', $url);

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
        $url = $this->getDatabaseUrl() . "/globals/";

        // Prepare the data to send
        $data = [
            'globalFields' => $globalFields
        ];

        $response = $this->makeRequest('patch', $url, $data);

        return $response;
    }

    protected function makeRequest($method, $url, $params = [], ?PendingRequest $request = null)
    {
        $this->login();

        if ($request instanceof PendingRequest) {
            $request->withToken($this->sessionToken);
        } else {
            $request = Http::withToken($this->sessionToken);
        }

        $response = $request->withMiddleware($this->retryMiddleware())
            ->{$method}($url, $params)->throw()->json();

        // Check for errors
        $this->checkResponseForErrors($response);

        return $response;
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
            RequestException $exception = null
        ) {
            // Limit the number of retries to 5
            if ($retries >= $this->retries) {
                return false;
            }

            $should_retry = false;
            $refresh = false;
            $log_message = null;

            // Retry connection exceptions
            if ($exception instanceof ConnectException) {
                $should_retry = true;
                $log_message = 'Connection Error: ' . $exception->getMessage();
            }

            $contents = $response->getBody()->getContents();
            $contents = json_decode($contents, true);
            if ($response && $response->getStatusCode() !== 200 && $contents !== null) {
                $code = (int)Arr::first(Arr::pluck(Arr::get($contents, 'messages'), 'code'));;
                if ($code === 952 && $retries <= 1) {
                    $refresh = true;
                    $should_retry = true;
                }
            }

            if ($log_message) {
                error_log($log_message, 0);
            }

            if ($refresh) {
                error_log("Refreshing Access Token…");
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
}
