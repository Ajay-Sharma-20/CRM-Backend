<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\TenantSetting;
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
}
