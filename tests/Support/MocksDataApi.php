<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for faking FileMaker Data API responses so the full request/response
 * cycle can be tested without a real FileMaker server.
 */
trait MocksDataApi
{
    protected function baseUrl(string $database = 'tester'): string
    {
        return 'https://filemaker.test/fmi/data/vLatest/databases/' . $database;
    }

    protected function layoutUrl(string $layout, string $database = 'tester'): string
    {
        return $this->baseUrl($database) . '/layouts/' . $layout;
    }

    /**
     * Fake the Data API endpoints. The session (login) endpoint is always
     * faked so any request which triggers a login will succeed.
     */
    protected function fakeDataApi(array $fakes = [], string $database = 'tester'): void
    {
        Http::fake(array_merge([
            $this->baseUrl($database) . '/sessions' => Http::response($this->fmResultResponse(['token' => 'test-session-token'])),
        ], $fakes));
    }

    /**
     * Build a single record as returned by the Data API.
     */
    protected function fmRecord(array $fieldData = [], array $portalData = [], string $recordId = '1', string $modId = '0'): array
    {
        return [
            'fieldData' => $fieldData,
            'portalData' => $portalData,
            'recordId' => $recordId,
            'modId' => $modId,
        ];
    }

    /**
     * A successful Data API response with an arbitrary response payload.
     */
    protected function fmResultResponse(array $response = []): array
    {
        return [
            'response' => $response,
            'messages' => [
                ['code' => '0', 'message' => 'OK'],
            ],
        ];
    }

    /**
     * A successful response containing a set of found records.
     */
    protected function fmRecordsResponse(array $records, ?int $foundCount = null): array
    {
        return $this->fmResultResponse([
            'dataInfo' => [
                'database' => 'tester',
                'returnedCount' => count($records),
                'foundCount' => $foundCount ?? count($records),
                'totalRecordCount' => $foundCount ?? count($records),
            ],
            'data' => $records,
        ]);
    }

    /**
     * An error response from the Data API, e.g. code 401 "No records match the request".
     */
    protected function fmErrorResponse(int $code, string $message = 'FileMaker Data API error'): array
    {
        return [
            'messages' => [
                ['code' => (string) $code, 'message' => $message],
            ],
            'response' => [],
        ];
    }

    /**
     * Get all recorded requests sent to a URL which starts with the given string,
     * optionally filtered by request method.
     */
    protected function recordedRequests(string $urlPrefix, ?string $method = null): array
    {
        return Http::recorded(function (Request $request) use ($urlPrefix, $method) {
            return str_starts_with($request->url(), $urlPrefix)
                && ($method === null || $request->method() === strtoupper($method));
        })->map(fn ($pair) => $pair[0])->values()->all();
    }

    /**
     * Get a request's body data normalized to plain arrays. Post data may
     * contain Collection objects (e.g. fieldData), which serialize to JSON
     * objects when actually sent over the wire.
     */
    protected function bodyData(Request $request): array
    {
        return json_decode(json_encode($request->data()), true);
    }

    /**
     * Parse the query string of a request's URL into an array.
     * PHP's parse_str() mangles keys containing dots (e.g. "script.param"),
     * so the pairs are split manually.
     */
    protected function queryParams(Request $request): array
    {
        $query = parse_url($request->url(), PHP_URL_QUERY);

        if ($query === null || $query === '') {
            return [];
        }

        $params = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($key)] = urldecode($value);
        }

        return $params;
    }

    /**
     * Get the single request sent to the given URL prefix, asserting exactly one was sent.
     */
    protected function recordedRequest(string $urlPrefix, ?string $method = null): Request
    {
        $requests = $this->recordedRequests($urlPrefix, $method);

        $this->assertCount(1, $requests, 'Expected exactly one request to ' . $urlPrefix);

        return $requests[0];
    }
}
