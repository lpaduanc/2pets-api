<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Check if user has admin role
        if ($request->user()->role !== 'admin') {
            \Log::warning('Unauthorized admin access attempt', [
                'user_id' => $request->user()->id,
                'email' => $request->user()->email,
                'role' => $request->user()->role,
                'ip' => $request->ip(),
                'route' => $request->path()
            ]);

            return response()->json([
                'message' => 'Acesso negado - Somente administradores',
                'required_role' => 'admin',
                'current_role' => $request->user()->role
            ], 403);
        }

        // Log admin access for audit trail
        \Log::info('Admin access', [
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => $request->method(),
            'route' => $request->path(),
            'ip' => $request->ip()
        ]);

        return $next($request);
    }
}
