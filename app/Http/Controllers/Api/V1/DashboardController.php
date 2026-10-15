<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function metrics(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $role = $user->role?->slug ?? 'telecaller';

        if ($user->isOwner() || $user->isAdmin()) {
            return $this->ownerAdminMetrics($tenantId);
        }

        if ($user->isManager()) {
            return $this->managerMetrics($user, $tenantId);
        }

        return $this->telecallerMetrics($user, $tenantId);
    }

    private function ownerAdminMetrics(int $tenantId): JsonResponse
    {
        $totalLeads = Lead::where('tenant_id', $tenantId)->count();
        $newLeadsThisWeek = Lead::where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->startOfWeek())
            ->count();
        $unassignedLeads = Lead::where('tenant_id', $tenantId)->whereNull('owner_id')->count();
        $assignedLeads = $totalLeads - $unassignedLeads;

        // Leads by status
        $statusCounts = Lead::where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Calls
        $totalCalls = CallLog::where('tenant_id', $tenantId)->count();
        $callsToday = CallLog::where('tenant_id', $tenantId)
            ->whereDate('called_at', Carbon::today())
            ->count();
        $connectedCalls = CallLog::where('tenant_id', $tenantId)
            ->whereIn('outcome', ['Connected', 'Interested', 'Follow-up Required', 'Demo/Meeting Scheduled', 'Converted'])
            ->count();
        $connectRate = $totalCalls > 0 ? round(($connectedCalls / $totalCalls) * 100, 1) : 0;

        // Follow-ups
        $followUpsDueToday = FollowUp::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->whereDate('due_at', Carbon::today())
            ->count();
        $followUpsOverdue = FollowUp::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->where('due_at', '<', Carbon::now())
            ->count();

        // Team stats
        $teams = Team::where('tenant_id', $tenantId)->withCount(['leads', 'members'])->get();

        // Conversion rate
        $convertedLeads = $statusCounts['Converted'] ?? 0;
        $conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'owner_admin',
                'summary' => [
                    'total_leads' => $totalLeads,
                    'new_leads_this_week' => $newLeadsThisWeek,
                    'assigned_leads' => $assignedLeads,
                    'unassigned_leads' => $unassignedLeads,
                    'calls_attempted_total' => $totalCalls,
                    'calls_today' => $callsToday,
                    'connected_calls' => $connectedCalls,
                    'connect_rate_percent' => $connectRate,
                    'follow_ups_due_today' => $followUpsDueToday,
                    'follow_ups_overdue' => $followUpsOverdue,
                    'conversion_rate_percent' => $conversionRate,
                ],
                'leads_by_status' => $statusCounts,
                'teams' => $teams,
            ],
        ]);
    }

    private function managerMetrics(User $user, int $tenantId): JsonResponse
    {
        // Find teams managed by user or user is member of
        $managedTeamIds = Team::where('tenant_id', $tenantId)
            ->where('leader_id', $user->id)
            ->pluck('id')
            ->toArray();

        if (empty($managedTeamIds)) {
            $managedTeamIds = $user->teams()->pluck('teams.id')->toArray();
        }

        $teamLeadsQuery = Lead::where('tenant_id', $tenantId)->whereIn('team_id', $managedTeamIds);
        $totalTeamLeads = (clone $teamLeadsQuery)->count();
        $unassignedTeamLeads = (clone $teamLeadsQuery)->whereNull('owner_id')->count();

        $statusCounts = (clone $teamLeadsQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Team members
        $memberIds = User::whereHas('teams', fn($q) => $q->whereIn('teams.id', $managedTeamIds))
            ->pluck('id')
            ->toArray();

        $callsByMember = User::whereIn('id', $memberIds)
            ->withCount([
                'callLogs as calls_count',
                'ownedLeads as assigned_leads_count',
                'followUps as overdue_followups_count' => fn($q) => $q->where('status', 'open')->where('due_at', '<', Carbon::now()),
            ])
            ->get();

        $followUpsOverdue = FollowUp::where('tenant_id', $tenantId)
            ->whereIn('user_id', $memberIds)
            ->where('status', 'open')
            ->where('due_at', '<', Carbon::now())
            ->count();

        $followUpsDueToday = FollowUp::where('tenant_id', $tenantId)
            ->whereIn('user_id', $memberIds)
            ->where('status', 'open')
            ->whereDate('due_at', Carbon::today())
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'manager',
                'summary' => [
                    'total_team_leads' => $totalTeamLeads,
                    'unassigned_team_leads' => $unassignedTeamLeads,
                    'assigned_team_leads' => $totalTeamLeads - $unassignedTeamLeads,
                    'follow_ups_due_today' => $followUpsDueToday,
                    'follow_ups_overdue' => $followUpsOverdue,
                ],
                'leads_by_status' => $statusCounts,
                'team_members' => $callsByMember,
            ],
        ]);
    }

    private function telecallerMetrics(User $user, int $tenantId): JsonResponse
    {
        $myLeadsQuery = Lead::where('tenant_id', $tenantId)->where('owner_id', $user->id);
        $myTotalLeads = (clone $myLeadsQuery)->count();

        $statusCounts = (clone $myLeadsQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $callsToday = CallLog::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereDate('called_at', Carbon::today())
            ->count();

        $connectedToday = CallLog::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereDate('called_at', Carbon::today())
            ->whereIn('outcome', ['Connected', 'Interested', 'Follow-up Required', 'Demo/Meeting Scheduled', 'Converted'])
            ->count();

        $dueToday = FollowUp::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->whereDate('due_at', Carbon::today())
            ->count();

        $overdue = FollowUp::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->where('due_at', '<', Carbon::now())
            ->count();

        // Next eligible lead for calling
        $nextLead = Lead::where('tenant_id', $tenantId)
            ->where('owner_id', $user->id)
            ->whereIn('status', ['Assigned', 'New', 'Follow-up', 'Contacted'])
            ->orderBy('updated_at', 'asc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'telecaller',
                'summary' => [
                    'my_assigned_leads' => $myTotalLeads,
                    'calls_today' => $callsToday,
                    'connected_today' => $connectedToday,
                    'follow_ups_due_today' => $dueToday,
                    'follow_ups_overdue' => $overdue,
                    'converted_leads' => $statusCounts['Converted'] ?? 0,
                ],
                'leads_by_status' => $statusCounts,
                'next_lead' => $nextLead,
            ],
        ]);
    }
}
