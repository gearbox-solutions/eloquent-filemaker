<?php

namespace GearboxSolutions\EloquentFileMaker\Services;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Database\Query\Grammars\FMGrammar;
use GearboxSolutions\EloquentFileMaker\Database\Schema\FMBuilder;
use GearboxSolutions\EloquentFileMaker\Exceptions\FileMakerODataException;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\File\File;

class FileMakerConnection extends Connection
{
    protected string $protocol = 'https';

    protected int $attempts = 2;

    protected int $timeout = 30;

    protected bool $emptyStringToNull = true;

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        $this->emptyStringToNull = $config['empty_strings_to_null'] ?? true;

        $this->setTimeout($config['request_timeout'] ?? 30);

        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    protected function getDatabaseUrl(): string
    {
        return ($this->config['protocol'] ?? 'https') . '://' . $this->config['host'] . '/fmi/odata/v4/' . $this->config['database'];
    }

    protected function getTableUrl(string $table): string
    {
        return $this->getDatabaseUrl() . '/' . $this->tablePrefix . $table;
    }

    /**
     * Run the given query and return the flat array of matching records.
     *
     * @throws FileMakerODataException
     */
    public function getRecords(FMBaseBuilder $query): array
    {
        $response = $this->makeRequest('get', $this->getTableUrl($query->from), $this->buildQueryParams($query));

        return $response['value'] ?? [];
    }

    /**
     * Run the given query and return both the page of records and the total match count
     * (via OData's $count=true), for use in paginated results.
     *
     * @throws FileMakerODataException
     */
    public function selectWithCount(FMBaseBuilder $query): array
    {
        $params = $this->buildQueryParams($query);
        $params['$count'] = 'true';

        return $this->makeRequest('get', $this->getTableUrl($query->from), $params);
    }

    /**
     * Get the count of records matching the query, using OData's $count path segment.
     *
     * @throws FileMakerODataException
     */
    public function count(FMBaseBuilder $query): int
    {
        $filter = $query->getGrammar()->compileWheres($query);

        $url = $this->getTableUrl($query->from) . '/$count';

        $response = $this->makeRawRequest('get', $url, $filter !== '' ? ['$filter' => $filter] : []);

        return (int) trim($response->body());
    }

    protected function buildQueryParams(FMBaseBuilder $query): array
    {
        $params = [];

        $filter = $query->getGrammar()->compileWheres($query);
        if ($filter !== '') {
            $params['$filter'] = $filter;
        }

        $orderBy = $query->getGrammar()->compileOrders($query, $query->orders ?? []);
        if ($orderBy !== '') {
            $params['$orderby'] = $orderBy;
        }

        if (! is_null($query->limit)) {
            $params['$top'] = $query->limit;
        }

        if (! is_null($query->offset)) {
            $params['$skip'] = $query->offset;
        }

        return $params;
    }

    /**
     * Create a record and return the created entity as returned by the OData API.
     *
     * @throws FileMakerODataException
     */
    public function createRecord(FMBaseBuilder $query): array
    {
        $data = $this->buildWriteData($query);

        return $this->makeRequest('post', $this->getTableUrl($query->from), empty($data) ? new \stdClass : $data);
    }

    /**
     * Update the record(s) matched by the query in bulk, via a $filter on the collection
     * endpoint (supported by FileMaker's OData API for both single and multiple records).
     * A cheap $count request is made first so the return value accurately reflects the
     * number of records affected.
     *
     * @param  FMBaseBuilder  $query
     * @return int the number of records affected
     *
     * @throws FileMakerODataException
     */
    public function update($query, $bindings = [])
    {
        $data = $this->buildWriteData($query);

        if (empty($data)) {
            return 0;
        }

        $affected = $this->count($query);

        if ($affected === 0) {
            return 0;
        }

        $this->makeRawRequest('patch', $this->getTableUrl($query->from) . $this->queryStringSuffix($query), $data);

        return $affected;
    }

    /**
     * Delete the record(s) matched by the query in bulk, via a $filter on the collection
     * endpoint, using the same count-then-write approach as update().
     *
     * @param  FMBaseBuilder  $query
     * @return int the number of records affected
     *
     * @throws FileMakerODataException
     */
    public function delete($query, $bindings = [])
    {
        $affected = $this->count($query);

        if ($affected === 0) {
            return 0;
        }

        $this->makeRawRequest('delete', $this->getTableUrl($query->from) . $this->queryStringSuffix($query));

        return $affected;
    }

    protected function queryStringSuffix(FMBaseBuilder $query): string
    {
        $filter = $query->getGrammar()->compileWheres($query);

        if ($filter === '') {
            return '';
        }

        return '?' . http_build_query(['$filter' => $filter], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Build the flat write payload for a query's fieldData, base64-encoding any container
     * (file) values and converting nulls to empty strings for FileMaker's write convention.
     */
    protected function buildWriteData(FMBaseBuilder $query): array
    {
        return (new Collection($query->fieldData ?? []))->map(function ($value) {
            if ($this->isContainerValue($value)) {
                return $this->encodeContainerValue($value);
            }

            return $value === null ? '' : $value;
        })->all();
    }

    protected function isContainerValue($value): bool
    {
        if (is_a($value, File::class)) {
            return true;
        }

        if (is_array($value) && count($value) === 2 && $this->isFile($value[0])) {
            return true;
        }

        return false;
    }

    protected function isFile($object): bool
    {
        return is_a($object, \Illuminate\Http\File::class) || is_a($object, UploadedFile::class);
    }

    protected function encodeContainerValue($value): string
    {
        $file = is_array($value) ? $value[0] : $value;

        return base64_encode(file_get_contents($file->getRealPath()));
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
     * Fetch and parse the $metadata document for the database, returning the field names
     * declared for the given table's EntityType.
     *
     * @throws FileMakerODataException
     */
    public function getTableMetadata(string $table): array
    {
        $response = $this->makeRawRequest('get', $this->getDatabaseUrl() . '/$metadata');

        $xml = new SimpleXMLElement($response->body());
        $xml->registerXPathNamespace('edm', 'http://docs.oasis-open.org/odata/ns/edm');

        $tableName = $this->tablePrefix . $table;
        $properties = $xml->xpath("//edm:EntityType[@Name='" . addslashes($tableName) . "']/edm:Property");

        if (empty($properties)) {
            throw new FileMakerODataException("No OData entity type found for table [{$tableName}].");
        }

        $fields = [];
        foreach ($properties as $property) {
            $fields[] = (string) $property['Name'];
        }

        return $fields;
    }

    protected function prepareRequestForSending(?PendingRequest $request = null): PendingRequest
    {
        if (! $request) {
            if (method_exists(Factory::class, 'createPendingRequest')) {
                $request = Http::createPendingRequest();
            } else {
                $request = Http::acceptJson();
            }
        }

        $request->timeout($this->timeout)
            ->retry($this->attempts, 100, fn () => true, false)
            ->acceptJson()
            ->withBasicAuth($this->config['username'], $this->config['password']);

        return $request;
    }

    /**
     * @throws FileMakerODataException
     */
    protected function makeRequest($method, $url, $params = [], ?PendingRequest $request = null)
    {
        return $this->sendRequest($method, $url, $params, $request)->json();
    }

    /**
     * @throws FileMakerODataException
     */
    protected function makeRawRequest($method, $url, $params = [], ?PendingRequest $request = null): Response
    {
        return $this->sendRequest($method, $url, $params, $request);
    }

    /**
     * @throws FileMakerODataException
     */
    protected function sendRequest($method, $url, $params, ?PendingRequest $request): Response
    {
        $start = microtime(true);

        $request = $this->prepareRequestForSending($request);

        try {
            $response = $request->{$method}($url, $params);
        } catch (\Exception $e) {
            $this->logODataQuery($method, $url, $params, $start);
            throw $e;
        }

        $this->logODataQuery($method, $url, $params, $start);

        $this->checkResponseForErrors($response);

        return $response;
    }

    /**
     * @throws FileMakerODataException
     */
    protected function checkResponseForErrors(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error');

        $message = is_array($error)
            ? ($error['message'] ?? json_encode($error))
            : ($response->body() ?: $response->reason());

        throw new FileMakerODataException(is_string($message) ? $message : json_encode($message), $response->status());
    }

    protected function logODataQuery($method, $url, $params, $start): void
    {
        $commandType = $this->getSqlCommandType($method);

        // Clockwork specifically looks for the commandType as the first word in the "sql" string
        $sql = <<<DOC
                {$commandType}
                Method: {$method}
                URL: {$url}
                DOC;

        if (! empty($params)) {
            $sql .= "\nData: " . json_encode($params, JSON_PRETTY_PRINT);
        }

        $this->event(new QueryExecuted(
            $sql,
            [],
            $this->getElapsedTime($start),
            $this,
        ));
    }

    protected function getSqlCommandType($method): string
    {
        return match (strtolower($method)) {
            'delete' => 'delete',
            'patch', 'put' => 'update',
            'post' => 'insert',
            default => 'select',
        };
    }

    public function setRetries($retries)
    {
        $this->attempts = $retries + 1;

        return $this;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }

    protected function getDefaultQueryGrammar()
    {
        // check if this is laravel 11 or 12
        // Laravel 11 constructs a grammar without any parameters
        // Laravel 12 requires a connection as a constructor parameter
        $version = app()->version();
        // get the major version number
        $majorVersion = (int) explode('.', $version)[0];
        if ($majorVersion < 12) {
            // Laravel 11 and earlier
            return new FMGrammar;
        }

        // Laravel 12
        return new FMGrammar($this);

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
