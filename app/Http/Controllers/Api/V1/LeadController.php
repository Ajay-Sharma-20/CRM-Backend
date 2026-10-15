<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadNote;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    /**
     * List leads with role-based scoping, multi-field search, filters, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = Lead::where('tenant_id', $tenantId)->with(['owner:id,name,email', 'team:id,name']);

        // Data Scope Enforcement:
        if ($user->isTelecaller()) {
            $query->where('owner_id', $user->id);
        } elseif ($user->isManager()) {
            $managedTeamIds = Team::where('tenant_id', $tenantId)
                ->where('leader_id', $user->id)
                ->pluck('id')
                ->toArray();
            if (empty($managedTeamIds)) {
                $managedTeamIds = $user->teams()->pluck('teams.id')->toArray();
            }
            $query->where(function ($q) use ($managedTeamIds, $user) {
                $q->whereIn('team_id', $managedTeamIds)
                  ->orWhere('owner_id', $user->id);
            });
        }
        // Owner and Admin see all tenant leads

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        if ($ownerId = $request->input('owner_id')) {
            if ($ownerId === 'unassigned') {
                $query->whereNull('owner_id');
            } else {
                $query->where('owner_id', $ownerId);
            }
        }
        if ($teamId = $request->input('team_id')) {
            $query->where('team_id', $teamId);
        }

        // Date range
        if ($from = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $leads = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $leads->items(),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
            ],
        ]);
    }

    /**
     * Show single lead detail with notes, activity timeline, calls, and follow-ups.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lead = Lead::where('tenant_id', $user->tenant_id)
            ->with([
                'owner:id,name,email,phone',
                'team:id,name',
                'creator:id,name',
                'notes.user:id,name',
                'callLogs.user:id,name',
                'followUps.user:id,name',
                'activities.user:id,name',
            ])
            ->findOrFail($id);

        // Security check for telecallers
        if ($user->isTelecaller() && $lead->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You may only view leads assigned directly to you.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $lead,
        ]);
    }

    /**
     * Create new lead.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'company' => 'nullable|string|max:150',
            'job_title' => 'nullable|string|max:100',
            'status' => 'nullable|string',
            'source' => 'nullable|string',
            'owner_id' => 'nullable|exists:users,id',
            'team_id' => 'nullable|exists:teams,id',
            'estimated_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'ignore_duplicate_warning' => 'nullable|boolean',
        ]);

        $normalizedPhone = Lead::normalizePhone($validated['phone']);
        $normalizedEmail = Lead::normalizeEmail($validated['email'] ?? null);

        // Duplicate Check
        if (empty($validated['ignore_duplicate_warning'])) {
            $duplicateQuery = Lead::where('tenant_id', $user->tenant_id)
                ->where(function ($q) use ($normalizedPhone, $normalizedEmail) {
                    $q->where('normalized_phone', $normalizedPhone);
                    if (!empty($normalizedEmail)) {
                        $q->orWhere('normalized_email', $normalizedEmail);
                    }
                });

            $duplicate = $duplicateQuery->first();
            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'is_duplicate' => true,
                    'message' => "Potential duplicate detected: Lead '{$duplicate->first_name} {$duplicate->last_name}' already exists with this phone or email.",
                    'duplicate_lead' => [
                        'id' => $duplicate->id,
                        'name' => "{$duplicate->first_name} {$duplicate->last_name}",
                        'phone' => $duplicate->phone,
                        'email' => $duplicate->email,
                        'status' => $duplicate->status,
                        'owner' => $duplicate->owner?->name ?? 'Unassigned',
                    ],
                ], 409);
            }
        }

        $lead = DB::transaction(function () use ($validated, $user) {
            $lead = Lead::create(array_merge($validated, [
                'tenant_id' => $user->tenant_id,
                'status' => $validated['status'] ?? 'New',
                'source' => $validated['source'] ?? 'Website',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]));

            // Add note if provided
            if (!empty($validated['notes'])) {
                LeadNote::create([
                    'tenant_id' => $user->tenant_id,
                    'lead_id' => $lead->id,
                    'user_id' => $user->id,
                    'content' => $validated['notes'],
                ]);
            }

            // Activity timeline
            LeadActivity::create([
                'tenant_id' => $user->tenant_id,
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'type' => 'created',
                'description' => "Lead created by {$user->name}.",
            ]);

            // Audit
            AuditEvent::log(
                $user->tenant_id,
                $user->id,
                'lead_created',
                'Lead',
                $lead->id,
                "Lead {$lead->first_name} {$lead->last_name} created.",
                null,
                $lead->toArray()
            );

            return $lead;
        });

        return response()->json([
            'success' => true,
            'message' => 'Lead created successfully.',
            'data' => $lead->load(['owner', 'team']),
        ], 201);
    }

    /**
     * Update existing lead.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lead = Lead::where('tenant_id', $user->tenant_id)->findOrFail($id);

        if ($user->isTelecaller() && $lead->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You cannot modify leads not assigned to you.',
            ], 403);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'sometimes|required|string|max:30',
            'email' => 'nullable|email|max:150',
            'company' => 'nullable|string|max:150',
            'job_title' => 'nullable|string|max:100',
            'status' => 'sometimes|required|string',
            'source' => 'sometimes|required|string',
            'owner_id' => 'nullable|exists:users,id',
            'team_id' => 'nullable|exists:teams,id',
            'estimated_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $lead->toArray();
        $oldStatus = $lead->status;

        $lead->update(array_merge($validated, ['updated_by' => $user->id]));

        // Log status change if modified
        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            LeadActivity::create([
                'tenant_id' => $user->tenant_id,
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'type' => 'status_change',
                'description' => "Status changed from '{$oldStatus}' to '{$validated['status']}'.",
            ]);
        }

        // Add note if new note text given
        if (!empty($validated['notes']) && $validated['notes'] !== $oldValues['notes']) {
            LeadNote::create([
                'tenant_id' => $user->tenant_id,
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'content' => $validated['notes'],
            ]);
        }

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'lead_updated',
            'Lead',
            $lead->id,
            "Lead {$lead->first_name} {$lead->last_name} updated by {$user->name}.",
            $oldValues,
            $lead->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully.',
            'data' => $lead->load(['owner', 'team']),
        ]);
    }

    /**
     * Soft delete lead.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lead = Lead::where('tenant_id', $user->tenant_id)->findOrFail($id);

        $lead->delete();

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'lead_deleted',
            'Lead',
            $lead->id,
            "Lead {$lead->first_name} {$lead->last_name} deleted by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully.',
        ]);
    }

    /**
     * Assign or reassign lead to user or team.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lead = Lead::where('tenant_id', $user->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'owner_id' => 'nullable|exists:users,id',
            'team_id' => 'nullable|exists:teams,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $prevOwner = $lead->owner?->name ?? 'Unassigned';
        $lead->owner_id = $validated['owner_id'] ?? null;
        if (array_key_exists('team_id', $validated)) {
            $lead->team_id = $validated['team_id'];
        }
        if ($lead->status === 'New' && $lead->owner_id) {
            $lead->status = 'Assigned';
        }
        $lead->save();

        $newOwnerUser = $lead->owner_id ? User::find($lead->owner_id) : null;
        $newOwnerName = $newOwnerUser?->name ?? 'Unassigned';

        // Log activity
        LeadActivity::create([
            'tenant_id' => $user->tenant_id,
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'type' => 'assignment',
            'description' => "Reassigned from {$prevOwner} to {$newOwnerName}." . (!empty($validated['reason']) ? " Reason: {$validated['reason']}" : ''),
        ]);

        // Send in-app notification if new owner
        if ($newOwnerUser && $newOwnerUser->id !== $user->id) {
            Notification::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $newOwnerUser->id,
                'title' => 'Lead Assigned',
                'message' => "Lead {$lead->first_name} {$lead->last_name} has been assigned to you by {$user->name}.",
                'type' => 'assignment',
                'link' => "/leads/{$lead->id}",
            ]);
        }

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'lead_assigned',
            'Lead',
            $lead->id,
            "Lead {$lead->id} assigned to {$newOwnerName} by {$user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => "Lead assigned to {$newOwnerName} successfully.",
            'data' => $lead->load(['owner', 'team']),
        ]);
    }

    /**
     * Check duplicate phone/email endpoint.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $user = $request->user();
        $phone = $request->input('phone');
        $email = $request->input('email');

        $normPhone = Lead::normalizePhone($phone);
        $normEmail = Lead::normalizeEmail($email);

        $matches = Lead::where('tenant_id', $user->tenant_id)
            ->where(function ($q) use ($normPhone, $normEmail) {
                if ($normPhone) {
                    $q->where('normalized_phone', $normPhone);
                }
                if ($normEmail) {
                    $q->orWhere('normalized_email', $normEmail);
                }
            })
            ->with(['owner:id,name'])
            ->get(['id', 'first_name', 'last_name', 'phone', 'email', 'status', 'owner_id', 'created_at']);

        return response()->json([
            'success' => true,
            'has_duplicate' => $matches->isNotEmpty(),
            'duplicates' => $matches,
        ]);
    }

    /**
     * Import leads from CSV text or file upload with preview and validation.
     */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'csv_content' => 'required_without:file|string',
            'file' => 'required_without:csv_content|file|mimes:csv,txt|max:5120',
        ]);

        $content = $request->hasFile('file')
            ? file_get_contents($request->file('file')->getRealPath())
            : $request->input('csv_content');

        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        if (count($lines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'CSV file must contain a header row and at least one data row.',
            ], 422);
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $colMap = [
            'first_name' => array_search('first_name', $header) !== false ? array_search('first_name', $header) : array_search('name', $header),
            'last_name' => array_search('last_name', $header),
            'phone' => array_search('phone', $header) !== false ? array_search('phone', $header) : array_search('mobile', $header),
            'email' => array_search('email', $header),
            'company' => array_search('company', $header),
            'status' => array_search('status', $header),
            'source' => array_search('source', $header),
            'estimated_value' => array_search('estimated_value', $header) !== false ? array_search('estimated_value', $header) : array_search('value', $header),
        ];

        if ($colMap['phone'] === false) {
            return response()->json([
                'success' => false,
                'message' => "CSV must include a 'phone' or 'mobile' header column.",
            ], 422);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $index => $line) {
            if (empty(trim($line))) continue;

            $rowNum = $index + 2; // account for header
            $cols = str_getcsv($line);

            $phone = trim($cols[$colMap['phone']] ?? '');
            if (!$phone) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Missing required phone number.";
                continue;
            }

            $normPhone = Lead::normalizePhone($phone);
            // Duplicate check
            $exists = Lead::where('tenant_id', $user->tenant_id)->where('normalized_phone', $normPhone)->exists();
            if ($exists) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Duplicate phone {$phone} already in database.";
                continue;
            }

            $firstName = $colMap['first_name'] !== false ? trim($cols[$colMap['first_name']] ?? 'Lead') : 'Lead';
            $lastName = $colMap['last_name'] !== false ? trim($cols[$colMap['last_name']] ?? '') : '';
            $email = $colMap['email'] !== false ? trim($cols[$colMap['email']] ?? '') : null;
            $company = $colMap['company'] !== false ? trim($cols[$colMap['company']] ?? '') : null;
            $status = $colMap['status'] !== false ? trim($cols[$colMap['status']] ?? 'New') : 'New';
            $source = $colMap['source'] !== false ? trim($cols[$colMap['source']] ?? 'CSV Import') : 'CSV Import';
            $val = $colMap['estimated_value'] !== false ? (float) ($cols[$colMap['estimated_value']] ?? 0) : null;

            Lead::create([
                'tenant_id' => $user->tenant_id,
                'first_name' => $firstName ?: 'Lead',
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email,
                'company' => $company,
                'status' => $status ?: 'New',
                'source' => $source ?: 'CSV Import',
                'estimated_value' => $val,
                'created_by' => $user->id,
            ]);

            $imported++;
        }

        AuditEvent::log(
            $user->tenant_id,
            $user->id,
            'leads_imported',
            'Lead',
            null,
            "CSV Lead Import by {$user->name}: {$imported} imported, {$skipped} skipped."
        );

        return response()->json([
            'success' => true,
            'message' => "Import complete. {$imported} leads successfully imported, {$skipped} skipped.",
            'data' => [
                'imported_count' => $imported,
                'skipped_count' => $skipped,
                'errors' => $errors,
            ],
        ]);
    }

    /**
     * Export tenant leads to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        AuditEvent::log(
            $tenantId,
            $user->id,
            'leads_exported',
            'Lead',
            null,
            "CSV Lead Export requested by {$user->name}."
        );

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="crm_leads_' . date('Y-m-d_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($tenantId, $user) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'First Name', 'Last Name', 'Phone', 'Email', 'Company', 'Status', 'Source', 'Owner', 'Estimated Value', 'Created At']);

            $query = Lead::where('tenant_id', $tenantId)->with('owner:id,name');
            if ($user->isTelecaller()) {
                $query->where('owner_id', $user->id);
            }

            $query->chunk(200, function ($leads) use ($handle) {
                foreach ($leads as $l) {
                    fputcsv($handle, [
                        $l->id,
                        $l->first_name,
                        $l->last_name,
                        $l->phone,
                        $l->email,
                        $l->company,
                        $l->status,
                        $l->source,
                        $l->owner?->name ?? 'Unassigned',
                        $l->estimated_value,
                        $l->created_at->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
