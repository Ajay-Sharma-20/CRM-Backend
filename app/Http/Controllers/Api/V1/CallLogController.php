<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CallLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = CallLog::where('tenant_id', $tenantId)->with(['lead:id,first_name,last_name,phone,company', 'user:id,name']);

        if ($user->isTelecaller()) {
            $query->where('user_id', $user->id);
        }

        if ($leadId = $request->input('lead_id')) {
            $query->where('lead_id', $leadId);
        }

        if ($outcome = $request->input('outcome')) {
            $query->where('outcome', $outcome);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 5), 100);
        $calls = $query->orderBy('called_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $calls->items(),
            'meta' => [
                'current_page' => $calls->currentPage(),
                'last_page' => $calls->lastPage(),
                'total' => $calls->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'outcome' => 'required|string',
            'duration_seconds' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            // Follow-up scheduling
            'schedule_follow_up' => 'nullable|boolean',
            'follow_up_due_at' => 'required_if:schedule_follow_up,true|required_if:outcome,Follow-up Required|nullable|date',
            'follow_up_title' => 'nullable|string|max:150',
            'follow_up_notes' => 'nullable|string',
            'update_lead_status' => 'nullable|string',
        ]);

        $lead = Lead::where('tenant_id', $user->tenant_id)->findOrFail($validated['lead_id']);

        if ($user->isTelecaller() && $lead->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only log calls for leads assigned to you.',
            ], 403);
        }

        $result = DB::transaction(function () use ($validated, $lead, $user) {
            $callLog = CallLog::create([
                'tenant_id' => $user->tenant_id,
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'outcome' => $validated['outcome'],
                'duration_seconds' => $validated['duration_seconds'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'called_at' => Carbon::now(),
            ]);

            // Update lead status if requested or map from outcome
            if (!empty($validated['update_lead_status'])) {
                $lead->status = $validated['update_lead_status'];
                $lead->save();
            } elseif (in_array($validated['outcome'], ['Interested', 'Qualified', 'Converted', 'Lost'])) {
                $lead->status = $validated['outcome'];
                $lead->save();
            } elseif ($validated['outcome'] === 'Follow-up Required') {
                $lead->status = 'Follow-up';
                $lead->save();
            } elseif ($lead->status === 'New' || $lead->status === 'Assigned') {
                $lead->status = 'Contacted';
                $lead->save();
            }

            // Timeline event
            $durationFormatted = !empty($validated['duration_seconds'])
                ? ' (' . gmdate("i:s", $validated['duration_seconds']) . ')'
                : '';
            LeadActivity::create([
                'tenant_id' => $user->tenant_id,
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'type' => 'call_logged',
                'description' => "Call logged by {$user->name}. Outcome: {$validated['outcome']}{$durationFormatted}." . (!empty($validated['notes']) ? " Note: {$validated['notes']}" : ''),
                'metadata' => [
                    'call_log_id' => $callLog->id,
                    'outcome' => $validated['outcome'],
                    'duration' => $validated['duration_seconds'] ?? null,
                ],
            ]);

            // Create Follow-up if requested or if outcome is Follow-up Required
            $followUp = null;
            if (!empty($validated['follow_up_due_at'])) {
                $followUp = FollowUp::create([
                    'tenant_id' => $user->tenant_id,
                    'lead_id' => $lead->id,
                    'user_id' => $user->id,
                    'title' => $validated['follow_up_title'] ?: "Follow-up after Call ({$validated['outcome']})",
                    'due_at' => $validated['follow_up_due_at'],
                    'status' => 'open',
                    'notes' => $validated['follow_up_notes'] ?: $validated['notes'],
                ]);

                LeadActivity::create([
                    'tenant_id' => $user->tenant_id,
                    'lead_id' => $lead->id,
                    'user_id' => $user->id,
                    'type' => 'follow_up_scheduled',
                    'description' => "Follow-up scheduled for " . Carbon::parse($validated['follow_up_due_at'])->format('M d, Y h:i A') . " by {$user->name}.",
                ]);
            }

            AuditEvent::log(
                $user->tenant_id,
                $user->id,
                'call_logged',
                'CallLog',
                $callLog->id,
                "Call recorded for Lead {$lead->id} ({$lead->first_name}) by {$user->name}. Outcome: {$validated['outcome']}."
            );

            return [
                'call_log' => $callLog->load('user:id,name'),
                'follow_up' => $followUp,
                'lead' => $lead->fresh(['owner', 'team']),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Call outcome recorded successfully.',
            'data' => $result,
        ], 201);
    }
}
