<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $users = User::where('tenant_id', $tenantId)
            ->with(['role:id,name,slug', 'teams:id,name'])
            ->withCount(['ownedLeads as leads_count', 'callLogs as calls_count'])
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'team_ids' => 'nullable|array',
            'team_ids.*' => 'exists:teams,id',
        ]);

        // Check unique within tenant
        $exists = User::where('tenant_id', $currentUser->tenant_id)->where('email', $validated['email'])->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'A user with this email already exists in your workspace.',
            ], 422);
        }

        // Prevent non-owners from creating owners
        $targetRole = Role::find($validated['role_id']);
        if ($targetRole->slug === 'owner' && !$currentUser->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only workspace owners can assign the Owner role.',
            ], 403);
        }

        $user = User::create([
            'tenant_id' => $currentUser->tenant_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'status' => 'active',
        ]);

        if (!empty($validated['team_ids'])) {
            $user->teams()->sync($validated['team_ids']);
        }

        AuditEvent::log(
            $currentUser->tenant_id,
            $currentUser->id,
            'user_created',
            'User',
            $user->id,
            "User '{$user->name}' ({$user->email}) created with role {$targetRole->name} by {$currentUser->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => $user->load(['role', 'teams']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $user = User::where('tenant_id', $currentUser->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'phone' => 'nullable|string|max:30',
            'role_id' => 'sometimes|required|exists:roles,id',
            'status' => 'sometimes|required|in:active,deactivated',
            'password' => 'nullable|string|min:8',
            'team_ids' => 'nullable|array',
            'team_ids.*' => 'exists:teams,id',
        ]);

        // Guard: cannot deactivate oneself
        if (isset($validated['status']) && $validated['status'] === 'deactivated' && $user->id === $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $oldValues = $user->toArray();

        $updateData = [
            'name' => $validated['name'] ?? $user->name,
            'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $user->phone,
            'status' => $validated['status'] ?? $user->status,
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        if (!empty($validated['role_id'])) {
            $targetRole = Role::find($validated['role_id']);
            if ($targetRole->slug === 'owner' && !$currentUser->isOwner()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only workspace owners can assign the Owner role.',
                ], 403);
            }
            $updateData['role_id'] = $validated['role_id'];
        }

        $user->update($updateData);

        if (array_key_exists('team_ids', $validated)) {
            $user->teams()->sync($validated['team_ids']);
        }

        AuditEvent::log(
            $currentUser->tenant_id,
            $currentUser->id,
            'user_updated',
            'User',
            $user->id,
            "User '{$user->name}' updated by {$currentUser->name}.",
            $oldValues,
            $user->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user->fresh(['role', 'teams']),
        ]);
    }
}
