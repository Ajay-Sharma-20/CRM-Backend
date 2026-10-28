<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\TenantSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $settings = TenantSetting::where('tenant_id', $tenantId)->get()->pluck('value', 'key');

        return response()->json([
            'success' => true,
            'data' => [
                'lead_statuses' => $settings['lead_statuses'] ?? ['New', 'Assigned', 'Contacted', 'Interested', 'Follow-up', 'Qualified', 'Converted', 'Lost', 'Invalid'],
                'call_outcomes' => $settings['call_outcomes'] ?? ['Connected', 'Interested', 'Follow-up Required', 'Call Later', 'Busy', 'No Answer', 'Not Reachable', 'Wrong Number', 'Not Interested', 'Demo/Meeting Scheduled', 'Converted', 'Lost'],
                'lead_sources' => $settings['lead_sources'] ?? ['Website', 'Referral', 'Cold Call', 'Campaign', 'Inbound', 'Social Media', 'Events'],
                'announcement' => $settings['announcement'] ?? [
                    'badge' => 'BROADCAST',
                    'date' => Carbon::now()->format('M d'),
                    'message' => 'Ajay Ji Sudarshan Tehnologies welcomes you',
                    'author' => 'SRIJAN SIR',
                    'active' => true,
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'lead_statuses' => 'nullable|array',
            'call_outcomes' => 'nullable|array',
            'lead_sources' => 'nullable|array',
            'announcement' => 'nullable|array',
        ]);

        foreach ($validated as $key => $val) {
            TenantSetting::updateOrCreate(
                ['tenant_id' => $user->tenant_id, 'key' => $key],
                ['value' => $val]
            );
        }

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'settings_updated',
            'TenantSetting',
            null,
            "Workspace settings updated by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully.',
            'data' => $validated,
        ]);
    }

    public function broadcastAnnouncement(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'message' => 'required|string|max:500',
            'badge' => 'nullable|string|max:50',
            'author' => 'nullable|string|max:100',
        ]);

        $announcement = [
            'badge' => $validated['badge'] ?? 'BROADCAST',
            'date' => Carbon::now()->format('M d'),
            'message' => $validated['message'],
            'author' => $validated['author'] ?? $user->name,
            'active' => true,
        ];

        TenantSetting::updateOrCreate(
            ['tenant_id' => $user->tenant_id, 'key' => 'announcement'],
            ['value' => $announcement]
        );

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'announcement_broadcasted',
            'TenantSetting',
            null,
            "New announcement broadcasted by {$user->name}: {$validated['message']}"
        );

        return response()->json([
            'success' => true,
            'message' => 'Announcement broadcasted successfully to all telecallers.',
            'data' => $announcement,
        ]);
    }
}
