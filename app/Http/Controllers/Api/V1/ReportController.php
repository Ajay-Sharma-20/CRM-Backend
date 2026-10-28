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
        $user = $request->user();

        $query = Lead::where('tenant_id', $tenantId);
        if ($user->role?->slug === 'telecaller') {
            $query->where('owner_id', $user->id);
        }

        $statusCounts = (clone $query)
            ->selectRaw('status, count(*) as count, sum(estimated_value) as total_value')
            ->groupBy('status')
            ->get();

        $sourceCounts = (clone $query)
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
        $user = $request->user();

        $query = CallLog::where('tenant_id', $tenantId);
        if ($user->role?->slug === 'telecaller') {
            $query->where('user_id', $user->id);
        }

        $outcomes = (clone $query)
            ->selectRaw('outcome, count(*) as count, avg(duration_seconds) as avg_duration')
            ->groupBy('outcome')
            ->get();

        // 14-day daily progress with attempts, connected calls, and conversions
        $recentCalls = (clone $query)
            ->where('called_at', '>=', Carbon::now()->subDays(14)->startOfDay())
            ->orderBy('called_at', 'desc')
            ->get();

        $dailyProgress = [];
        $connectedOutcomes = ['Connected', 'Interested', 'Follow-up Required', 'Demo/Meeting Scheduled', 'Converted'];

        for ($i = 0; $i < 14; $i++) {
            $dateObj = Carbon::now()->subDays($i);
            $dateStr = $dateObj->toDateString();
            $callsOnDate = $recentCalls->filter(fn($c) => Carbon::parse($c->called_at)->toDateString() === $dateStr);

            $attempts = $callsOnDate->count();
            $connected = $callsOnDate->whereIn('outcome', $connectedOutcomes)->count();
            $converted = $callsOnDate->where('outcome', 'Converted')->count();
            $totalSeconds = $callsOnDate->sum('duration_seconds');

            if ($i === 0) {
                $label = 'Today (' . $dateObj->format('M j') . ')';
            } elseif ($i === 1) {
                $label = 'Yesterday (' . $dateObj->format('M j') . ')';
            } else {
                $label = $dateObj->format('D, M j');
            }

            $dailyProgress[] = [
                'date' => $dateStr,
                'label' => $label,
                'attempts' => $attempts,
                'connected' => $connected,
                'converted' => $converted,
                'connect_rate' => $attempts > 0 ? round(($connected / $attempts) * 100, 1) : 0,
                'talk_time_minutes' => round($totalSeconds / 60, 1),
            ];
        }

        // Today's summary
        $todayCalls = $recentCalls->filter(fn($c) => Carbon::parse($c->called_at)->toDateString() === Carbon::today()->toDateString());
        $todayAttempts = $todayCalls->count();
        $todayConnected = $todayCalls->whereIn('outcome', $connectedOutcomes)->count();
        $todayConverted = $todayCalls->where('outcome', 'Converted')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'by_outcome' => $outcomes,
                'daily_progress' => $dailyProgress,
                'summary_today' => [
                    'attempts' => $todayAttempts,
                    'connected' => $todayConnected,
                    'converted' => $todayConverted,
                    'connect_rate' => $todayAttempts > 0 ? round(($todayConnected / $todayAttempts) * 100, 1) : 0,
                    'talk_time_minutes' => round($todayCalls->sum('duration_seconds') / 60, 1),
                ],
            ],
        ]);
    }

    public function teamPerformance(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $user = $request->user();

        $query = User::where('tenant_id', $tenantId);

        if ($user->role?->slug === 'telecaller') {
            $query->where('id', $user->id);
        } else {
            $query->whereHas('role', fn($q) => $q->whereIn('slug', ['telecaller', 'manager']));
        }

        $telecallers = $query
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
