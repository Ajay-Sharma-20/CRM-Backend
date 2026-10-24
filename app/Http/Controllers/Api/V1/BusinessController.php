<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    /**
     * List all businesses for tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $businesses = Business::where('tenant_id', $tenantId)
            ->withCount('leads')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $businesses,
        ]);
    }

    /**
     * Create new business with custom columns.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Control under Admin / Owner
        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only organization Administrators or Owners can create new business profiles.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'custom_columns' => 'nullable|array',
            'custom_columns.*.key' => 'required_with:custom_columns|string|max:60',
            'custom_columns.*.label' => 'required_with:custom_columns|string|max:100',
            'custom_columns.*.type' => 'nullable|string|in:text,number,date,select',
        ]);

        // Clean & format custom columns
        $columns = [];
        if (!empty($validated['custom_columns'])) {
            foreach ($validated['custom_columns'] as $col) {
                $rawKey = trim($col['key'] ?? '');
                $key = Str::slug($rawKey, '_');
                $label = trim($col['label'] ?? '') ?: Str::headline($key);
                $type = $col['type'] ?? 'text';
                if ($key) {
                    $columns[] = [
                        'key' => $key,
                        'label' => $label,
                        'type' => $type,
                    ];
                }
            }
        }

        $slugBase = Str::slug($validated['name']);
        $slug = $slugBase;
        $counter = 1;
        while (Business::where('tenant_id', $user->tenant_id)->where('slug', $slug)->exists()) {
            $slug = "{$slugBase}-{$counter}";
            $counter++;
        }

        $business = Business::create([
            'tenant_id' => $user->tenant_id,
            'name' => trim($validated['name']),
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'custom_columns' => $columns,
            'created_by' => $user->id,
        ]);

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'business_created',
            'Business',
            $business->id,
            "Business '{$business->name}' created with " . count($columns) . " custom columns by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => "Business '{$business->name}' created successfully.",
            'data' => $business->loadCount('leads'),
        ], 201);
    }

    /**
     * Show single business profile and schema.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $business = Business::where('tenant_id', $tenantId)
            ->withCount('leads')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $business,
        ]);
    }

    /**
     * Update business info and custom columns schema.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Administrators can update business profiles.',
            ], 403);
        }

        $business = Business::where('tenant_id', $user->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:500',
            'custom_columns' => 'nullable|array',
            'custom_columns.*.key' => 'required_with:custom_columns|string|max:60',
            'custom_columns.*.label' => 'required_with:custom_columns|string|max:100',
            'custom_columns.*.type' => 'nullable|string|in:text,number,date,select',
        ]);

        $columns = [];
        if (isset($validated['custom_columns'])) {
            foreach ($validated['custom_columns'] as $col) {
                $rawKey = trim($col['key'] ?? '');
                $key = Str::slug($rawKey, '_');
                $label = trim($col['label'] ?? '') ?: Str::headline($key);
                $type = $col['type'] ?? 'text';
                if ($key) {
                    $columns[] = [
                        'key' => $key,
                        'label' => $label,
                        'type' => $type,
                    ];
                }
            }
            $business->custom_columns = $columns;
        }

        if (!empty($validated['name'])) {
            $business->name = trim($validated['name']);
        }
        if (array_key_exists('description', $validated)) {
            $business->description = $validated['description'];
        }

        $business->save();

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'business_updated',
            'Business',
            $business->id,
            "Business '{$business->name}' updated by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => "Business '{$business->name}' updated successfully.",
            'data' => $business->loadCount('leads'),
        ]);
    }

    /**
     * Delete business.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Administrators can delete business profiles.',
            ], 403);
        }

        $business = Business::where('tenant_id', $user->tenant_id)->findOrFail($id);
        $name = $business->name;
        $business->delete();

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'business_deleted',
            'Business',
            $id,
            "Business '{$name}' deleted by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => "Business '{$name}' deleted successfully.",
        ]);
    }
}
