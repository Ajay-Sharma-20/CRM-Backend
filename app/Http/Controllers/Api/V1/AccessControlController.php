<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessControlController extends Controller
{
    /**
     * Get all roles for tenant with their assigned permissions and all available permissions catalogue.
     */
    public function getMatrix(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $roles = Role::where('tenant_id', $tenantId)
            ->with(['permissions:id,key,module,label'])
            ->get();

        $permissions = Permission::all()->groupBy('module');

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles,
                'permissions_by_module' => $permissions,
            ],
        ]);
    }

    /**
     * Update a role's permission set.
     */
    public function updateRolePermissions(Request $request, int $roleId): JsonResponse
    {
        $currentUser = $request->user();
        $role = Role::where('tenant_id', $currentUser->tenant_id)->findOrFail($roleId);

        // Security check: cannot strip core permissions from Owner
        if ($role->slug === 'owner') {
            return response()->json([
                'success' => false,
                'message' => 'The Owner role permissions are system-locked and cannot be modified.',
            ], 422);
        }

        $validated = $request->validate([
            'permission_ids' => 'present|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $oldPermIds = $role->permissions()->pluck('permissions.id')->toArray();

        // Privilege Escalation Check: non-owner admins cannot grant permissions they do not possess
        if (!$currentUser->isOwner()) {
            $userPerms = collect($currentUser->getEffectivePermissions());
            $requestedPerms = Permission::whereIn('id', $validated['permission_ids'])->pluck('key');
            $escalated = $requestedPerms->diff($userPerms);
            if ($escalated->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Privilege escalation prevented. You cannot grant permissions you do not possess: ' . $escalated->implode(', '),
                ], 403);
            }
        }

        $role->permissions()->sync($validated['permission_ids']);

        AuditEvent::log(
            $currentUser->tenant_id,
            $currentUser->id,
            'role_permissions_updated',
            'Role',
            $role->id,
            "Permissions for role '{$role->name}' updated by {$currentUser->name}.",
            ['permission_ids' => $oldPermIds],
            ['permission_ids' => $validated['permission_ids']]
        );

        return response()->json([
            'success' => true,
            'message' => "Permissions for role '{$role->name}' updated successfully.",
            'data' => $role->load('permissions'),
        ]);
    }

    /**
     * Get user-specific permissions breakdown: inherited role grants, explicit overrides, and effective permissions.
     */
    public function getUserPermissions(Request $request, int $userId): JsonResponse
    {
        $currentUser = $request->user();
        $targetUser = User::where('tenant_id', $currentUser->tenant_id)
            ->with(['role.permissions', 'permissionOverrides.permission'])
            ->findOrFail($userId);

        $allPermissions = Permission::all();
        $rolePermKeys = $targetUser->role ? $targetUser->role->permissions->pluck('key')->flip() : collect();
        $overrides = $targetUser->permissionOverrides->keyBy('permission.key');

        $breakdown = $allPermissions->map(function ($perm) use ($targetUser, $rolePermKeys, $overrides) {
            $key = $perm->key;
            $hasRoleGrant = $targetUser->isOwner() || isset($rolePermKeys[$key]);
            $override = $overrides[$key] ?? null;

            $status = 'default';
            $isEffective = false;

            if ($override !== null) {
                if ($override->is_granted) {
                    $status = 'explicit_grant';
                    $isEffective = true;
                } else {
                    $status = 'explicit_deny';
                    $isEffective = false;
                }
            } else {
                $status = $hasRoleGrant ? 'inherited' : 'denied';
                $isEffective = $hasRoleGrant;
            }

            return [
                'id' => $perm->id,
                'key' => $perm->key,
                'module' => $perm->module,
                'label' => $perm->label,
                'description' => $perm->description,
                'risk_level' => $perm->risk_level,
                'inherited_from_role' => $hasRoleGrant,
                'override' => $override ? [
                    'id' => $override->id,
                    'is_granted' => (bool) $override->is_granted,
                    'reason' => $override->reason,
                    'granted_by' => $override->grantor?->name,
                    'created_at' => $override->created_at,
                ] : null,
                'effective' => $isEffective,
                'status' => $status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'role' => $targetUser->role?->name,
                ],
                'permissions' => $breakdown,
            ],
        ]);
    }

    /**
     * Set explicit user permission override (grant or deny).
     */
    public function setUserOverride(Request $request, int $userId): JsonResponse
    {
        $currentUser = $request->user();
        $targetUser = User::where('tenant_id', $currentUser->tenant_id)->findOrFail($userId);

        $validated = $request->validate([
            'permission_id' => 'required|exists:permissions,id',
            'is_granted' => 'required|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        $permission = Permission::findOrFail($validated['permission_id']);

        // Prevent granting higher privileges than oneself
        if ($validated['is_granted'] && !$currentUser->isOwner() && !$currentUser->hasPermission($permission->key)) {
            return response()->json([
                'success' => false,
                'message' => "Privilege escalation prevented. You cannot grant permission '{$permission->key}' because you do not have it.",
            ], 403);
        }

        $override = UserPermissionOverride::updateOrCreate(
            [
                'user_id' => $targetUser->id,
                'permission_id' => $permission->id,
            ],
            [
                'is_granted' => $validated['is_granted'],
                'granted_by' => $currentUser->id,
                'reason' => $validated['reason'] ?? null,
            ]
        );

        $type = $validated['is_granted'] ? 'Explicit Grant' : 'Explicit Deny';
        AuditEvent::log(
            $currentUser->tenant_id,
            $currentUser->id,
            'user_permission_override_set',
            'UserPermissionOverride',
            $override->id,
            "{$type} override on '{$permission->key}' set for user {$targetUser->name} by {$currentUser->name}." . (!empty($validated['reason']) ? " Reason: {$validated['reason']}" : '')
        );

        return response()->json([
            'success' => true,
            'message' => "Permission override for '{$permission->key}' set to " . ($validated['is_granted'] ? 'ALLOW' : 'DENY') . ".",
            'data' => $override->load('permission'),
        ]);
    }

    /**
     * Remove explicit user permission override.
     */
    public function removeUserOverride(Request $request, int $userId, int $permissionId): JsonResponse
    {
        $currentUser = $request->user();
        $targetUser = User::where('tenant_id', $currentUser->tenant_id)->findOrFail($userId);

        $override = UserPermissionOverride::where('user_id', $targetUser->id)
            ->where('permission_id', $permissionId)
            ->first();

        if ($override) {
            $override->delete();

            AuditEvent::log(
                $currentUser->tenant_id,
                $currentUser->id,
                'user_permission_override_removed',
                'UserPermissionOverride',
                $permissionId,
                "Permission override removed for user {$targetUser->name} by {$currentUser->name}."
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Permission override removed. User now inherits standard role permission.',
        ]);
    }
}
