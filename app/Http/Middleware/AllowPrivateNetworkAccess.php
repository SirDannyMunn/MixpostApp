<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowPrivateNetworkAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Allow Private Network Access for local development
        if (app()->environment('local', 'development')) {
            $response->headers->set('Access-Control-Allow-Private-Network', 'true');
        }

        return $response;
    }
}
