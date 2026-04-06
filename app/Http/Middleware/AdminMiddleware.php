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
     * Allows access for users who have the 'admin' or 'super_admin'
     * Spatie role, OR the legacy 'admin' value in the `role` column
     * (for backward compatibility during migration).
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

        $user = $request->user();

        // Check via spatie/permission first, fall back to legacy column
        $hasAdminRole = $user->hasAnyRole(['admin', 'super_admin'])
            || $user->role === 'admin';

        if (!$hasAdminRole) {
            \Log::warning('Unauthorized admin access attempt', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'spatie_roles' => $user->getRoleNames()->toArray(),
                'ip' => $request->ip(),
                'route' => $request->path(),
            ]);

            return response()->json([
                'message' => 'Acesso negado - Somente administradores',
                'required_role' => 'admin',
                'current_role' => $user->role,
            ], 403);
        }

        // Log admin access for audit trail
        \Log::info('Admin access', [
            'admin_id' => $user->id,
            'admin_email' => $user->email,
            'action' => $request->method(),
            'route' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
