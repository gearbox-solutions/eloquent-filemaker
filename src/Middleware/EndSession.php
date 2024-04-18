<?php

namespace GearboxSolutions\EloquentFileMaker\Middleware;

use Closure;
use GearboxSolutions\EloquentFileMaker\Support\Facades\FM;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EndSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     * Terminate the FileMaker Session after the response has been sent to the browser.
     * Leave it active if the user has chosen to cache the session token.
     */
    public function terminate(Request $request, Response $response): void
    {
        $shouldCacheSessionToken = FM::connection()->getConfig()['cache_session_token'] ?? true;
        // don't close the connection if we're caching the session token
        if ($shouldCacheSessionToken) {
            return;
        }

        // disconnect the FileMaker session
        FM::disconnect();
    }
}
