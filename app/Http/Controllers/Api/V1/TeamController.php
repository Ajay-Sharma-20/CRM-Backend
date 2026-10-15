<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $teams = Team::where('tenant_id', $tenantId)
            ->with(['leader:id,name,email', 'members:id,name,email,phone,status'])
            ->withCount(['leads'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $teams,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'member_ids' => 'nullable|array',
            'member_ids.*' => 'exists:users,id',
        ]);

        $team = Team::create([
            'tenant_id' => $user->tenant_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'leader_id' => $validated['leader_id'] ?? null,
        ]);

        if (!empty($validated['member_ids'])) {
            $team->members()->sync($validated['member_ids']);
        }

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'team_created',
            'Team',
            $team->id,
            "Team '{$team->name}' created by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Team created successfully.',
            'data' => $team->load(['leader', 'members']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $team = Team::where('tenant_id', $user->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'member_ids' => 'nullable|array',
            'member_ids.*' => 'exists:users,id',
        ]);

        $team->update([
            'name' => $validated['name'] ?? $team->name,
            'description' => $validated['description'] ?? $team->description,
            'leader_id' => array_key_exists('leader_id', $validated) ? $validated['leader_id'] : $team->leader_id,
        ]);

        if (array_key_exists('member_ids', $validated)) {
            $team->members()->sync($validated['member_ids']);
        }

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'team_updated',
            'Team',
            $team->id,
            "Team '{$team->name}' updated by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Team updated successfully.',
            'data' => $team->fresh(['leader', 'members']),
        ]);
    }
}
