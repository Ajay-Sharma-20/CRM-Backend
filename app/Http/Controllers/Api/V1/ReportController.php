<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function leadsByStatus(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $statusCounts = Lead::where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as count, sum(estimated_value) as total_value')
            ->groupBy('status')
            ->get();

        $sourceCounts = Lead::where('tenant_id', $tenantId)
            ->selectRaw('source, count(*) as count')
            ->groupBy('source')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'by_status' => $statusCounts,
                'by_source' => $sourceCounts,
            ],
        ]);
    }

    public function callsByOutcome(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $outcomes = CallLog::where('tenant_id', $tenantId)
            ->selectRaw('outcome, count(*) as count, avg(duration_seconds) as avg_duration')
            ->groupBy('outcome')
            ->get();

        $dailyCalls = CallLog::where('tenant_id', $tenantId)
            ->where('called_at', '>=', Carbon::now()->subDays(14))
            ->selectRaw('date(called_at) as call_date, count(*) as attempts')
            ->groupBy('call_date')
            ->orderBy('call_date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'by_outcome' => $outcomes,
                'daily_trends' => $dailyCalls,
            ],
        ]);
    }

    public function teamPerformance(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $telecallers = User::where('tenant_id', $tenantId)
            ->whereHas('role', fn($q) => $q->whereIn('slug', ['telecaller', 'manager']))
            ->withCount([
                'ownedLeads as leads_assigned',
                'ownedLeads as leads_converted' => fn($q) => $q->where('status', 'Converted'),
                'callLogs as calls_attempted',
                'callLogs as calls_connected' => fn($q) => $q->whereIn('outcome', ['Connected', 'Interested', 'Follow-up Required', 'Demo/Meeting Scheduled', 'Converted']),
                'followUps as overdue_followups' => fn($q) => $q->where('status', 'open')->where('due_at', '<', Carbon::now()),
            ])
            ->get()
            ->map(function ($u) {
                $connectRate = $u->calls_attempted > 0 ? round(($u->calls_connected / $u->calls_attempted) * 100, 1) : 0;
                $convRate = $u->leads_assigned > 0 ? round(($u->leads_converted / $u->leads_assigned) * 100, 1) : 0;
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role?->name,
                    'leads_assigned' => $u->leads_assigned,
                    'leads_converted' => $u->leads_converted,
                    'calls_attempted' => $u->calls_attempted,
                    'calls_connected' => $u->calls_connected,
                    'connect_rate_percent' => $connectRate,
                    'conversion_rate_percent' => $convRate,
                    'overdue_followups' => $u->overdue_followups,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $telecallers,
        ]);
    }
}
