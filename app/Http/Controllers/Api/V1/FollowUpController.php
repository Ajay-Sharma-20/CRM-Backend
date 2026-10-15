<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = FollowUp::where('tenant_id', $tenantId)
            ->with(['lead:id,first_name,last_name,phone,company,status', 'user:id,name']);

        if ($user->isTelecaller()) {
            $query->where('user_id', $user->id);
        }

        $view = $request->input('view', 'open'); // open, today, overdue, upcoming, completed, cancelled, all

        if ($view === 'today') {
            $query->where('status', 'open')->whereDate('due_at', Carbon::today());
        } elseif ($view === 'overdue') {
            $query->where('status', 'open')->where('due_at', '<', Carbon::now());
        } elseif ($view === 'upcoming') {
            $query->where('status', 'open')->where('due_at', '>', Carbon::now());
        } elseif ($view === 'completed') {
            $query->where('status', 'completed');
        } elseif ($view === 'cancelled') {
            $query->where('status', 'cancelled');
        } elseif ($view === 'open') {
            $query->where('status', 'open');
        }

        if ($leadId = $request->input('lead_id')) {
            $query->where('lead_id', $leadId);
        }

        $followUps = $query->orderBy('due_at', 'asc')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $followUps->items(),
            'meta' => [
                'current_page' => $followUps->currentPage(),
                'last_page' => $followUps->lastPage(),
                'total' => $followUps->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'title' => 'required|string|max:150',
            'due_at' => 'required|date',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $lead = Lead::where('tenant_id', $user->tenant_id)->findOrFail($validated['lead_id']);

        $assignedUserId = $validated['user_id'] ?? $user->id;
        if ($user->isTelecaller()) {
            $assignedUserId = $user->id;
        }

        $followUp = FollowUp::create([
            'tenant_id' => $user->tenant_id,
            'lead_id' => $lead->id,
            'user_id' => $assignedUserId,
            'title' => $validated['title'],
            'due_at' => $validated['due_at'],
            'status' => 'open',
            'notes' => $validated['notes'] ?? null,
        ]);

        LeadActivity::create([
            'tenant_id' => $user->tenant_id,
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'type' => 'follow_up_scheduled',
            'description' => "Follow-up '{$followUp->title}' scheduled for " . Carbon::parse($validated['due_at'])->format('M d, Y h:i A') . " by {$user->name}.",
        ]);

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'followup_created',
            'FollowUp',
            $followUp->id,
            "Follow-up scheduled for lead {$lead->first_name} by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Follow-up scheduled successfully.',
            'data' => $followUp->load(['lead', 'user:id,name']),
        ], 201);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $followUp = FollowUp::where('tenant_id', $user->tenant_id)->findOrFail($id);

        if ($user->isTelecaller() && $followUp->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $followUp->update([
            'status' => 'completed',
            'completed_at' => Carbon::now(),
            'notes' => !empty($validated['notes'])
                ? ($followUp->notes ? $followUp->notes . "\n[Completed Note]: " . $validated['notes'] : $validated['notes'])
                : $followUp->notes,
        ]);

        LeadActivity::create([
            'tenant_id' => $user->tenant_id,
            'lead_id' => $followUp->lead_id,
            'user_id' => $user->id,
            'type' => 'follow_up_completed',
            'description' => "Follow-up '{$followUp->title}' completed by {$user->name}.",
        ]);

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'followup_completed',
            'FollowUp',
            $followUp->id,
            "Follow-up '{$followUp->title}' completed."
        );

        return response()->json([
            'success' => true,
            'message' => 'Follow-up marked as completed.',
            'data' => $followUp->fresh(['lead', 'user:id,name']),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $followUp = FollowUp::where('tenant_id', $user->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $followUp->update([
            'status' => 'cancelled',
            'cancelled_at' => Carbon::now(),
            'cancelled_reason' => $validated['reason'],
        ]);

        LeadActivity::create([
            'tenant_id' => $user->tenant_id,
            'lead_id' => $followUp->lead_id,
            'user_id' => $user->id,
            'type' => 'follow_up_cancelled',
            'description' => "Follow-up cancelled by {$user->name}. Reason: {$validated['reason']}",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Follow-up cancelled.',
            'data' => $followUp,
        ]);
    }
}
