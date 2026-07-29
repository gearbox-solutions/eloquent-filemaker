<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for faking FileMaker OData API responses so the full request/response
 * cycle can be tested without a real FileMaker server.
 */
trait MocksOData
{
    protected function baseUrl(string $database = 'tester'): string
    {
        return 'https://filemaker.test/fmi/odata/v4/' . $database;
    }

    protected function tableUrl(string $table, string $database = 'tester'): string
    {
        return $this->baseUrl($database) . '/' . $table;
    }

    /**
     * Fake the OData endpoints for a table with the given responses.
     */
    protected function fakeOData(array $fakes): void
    {
        Http::fake($fakes);
    }

    /**
     * A successful OData collection response, optionally including the @odata.count
     * annotation (as returned when a request includes $count=true).
     */
    protected function odataListResponse(array $records, ?int $count = null): array
    {
        $response = ['value' => $records];

        if ($count !== null) {
            $response['@odata.count'] = $count;
        }

        return $response;
    }

    /**
     * A plain-text response as returned by OData's /$count path segment.
     */
    protected function odataCountResponse(int $count)
    {
        return Http::response((string) $count, 200);
    }

    /**
     * An OData error response, e.g. a 404 for a missing record.
     */
    protected function odataErrorResponse(string $message, int $status = 400)
    {
        return Http::response(['error' => ['code' => (string) $status, 'message' => $message]], $status);
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
     * Get the single request sent to the given URL prefix, asserting exactly one was sent.
     */
    protected function recordedRequest(string $urlPrefix, ?string $method = null): Request
    {
        $requests = $this->recordedRequests($urlPrefix, $method);

        $this->assertCount(1, $requests, 'Expected exactly one request to ' . $urlPrefix . ($method ? " ({$method})" : ''));

        return $requests[0];
    }

    /**
     * Get a request's JSON body data normalized to plain arrays.
     */
    protected function bodyData(Request $request): array
    {
        return json_decode(json_encode($request->data()), true);
    }

    /**
     * Parse the query string of a request's URL into an array. PHP's parse_str() mangles
     * keys containing special characters (e.g. "$filter"), so the pairs are split manually.
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
}
