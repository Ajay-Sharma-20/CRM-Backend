<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->tenant_id || !$user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any active workspace.',
            ], 403);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'User account is deactivated. Please contact your company administrator.',
            ], 403);
        }

        if ($user->tenant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Workspace is suspended. Please contact support.',
            ], 403);
        }

        // Attach tenant to request attributes
        $request->attributes->set('tenant_id', $user->tenant_id);
        $request->attributes->set('tenant', $user->tenant);

        return $next($request);
    }
}
