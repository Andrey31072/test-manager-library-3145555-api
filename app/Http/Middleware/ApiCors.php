<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiCors
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withHeaders(response('', 204));
        }

        return $this->withHeaders($next($request));
    }

    private function withHeaders($response)
    {
        $origin = env('CORS_ORIGIN', '*');
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');

        return $response;
    }
}
