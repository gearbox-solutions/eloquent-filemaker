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
     */
    public function terminate(Request $request, Response $response): void
    {
        // disconnect the FileMaker session
        FM::disconnect();
    }
}
