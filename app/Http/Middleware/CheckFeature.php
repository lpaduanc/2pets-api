<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! config("features.$feature", false)) {
            abort(404);
        }

        return $next($request);
    }
}
