<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with(['tenant', 'role.permissions'])
            ->where('email', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact your company administrator.',
            ], 403);
        }

        if ($user->tenant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your company workspace is inactive or suspended.',
            ], 403);
        }

        // Create Sanctum API token
        $token = $user->createToken('crm-auth-token')->plainTextToken;

        // Audit login
        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'login',
            'User',
            $user->id,
            "User {$user->name} logged in successfully."
        );

        return response()->json([
            'success' => true,
            'message' => 'Signed in successfully.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => [
                        'id' => $user->role?->id,
                        'name' => $user->role?->name,
                        'slug' => $user->role?->slug,
                    ],
                    'tenant' => [
                        'id' => $user->tenant->id,
                        'name' => $user->tenant->name,
                        'slug' => $user->tenant->slug,
                        'timezone' => $user->tenant->timezone,
                    ],
                    'effective_permissions' => $user->getEffectivePermissions(),
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['tenant', 'role.permissions']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => [
                        'id' => $user->role?->id,
                        'name' => $user->role?->name,
                        'slug' => $user->role?->slug,
                    ],
                    'tenant' => [
                        'id' => $user->tenant->id,
                        'name' => $user->tenant->name,
                        'slug' => $user->tenant->slug,
                        'timezone' => $user->tenant->timezone,
                    ],
                    'effective_permissions' => $user->getEffectivePermissions(),
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->currentAccessToken()->delete();

            AuditEvent::log(
                $user->tenant_id,
                $user->id,
                'logout',
                'User',
                $user->id,
                "User {$user->name} logged out."
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Signed out successfully.',
        ]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy('module');

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }
}
