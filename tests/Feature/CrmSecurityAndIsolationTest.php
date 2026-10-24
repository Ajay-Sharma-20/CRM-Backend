<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmSecurityAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_can_login_and_receive_token_and_effective_permissions(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@apexdynamics.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => [
                        'id', 'name', 'email', 'role', 'tenant', 'effective_permissions'
                    ]
                ]
            ]);
    }

    public function test_tenant_isolation_prevents_tenant_a_user_from_accessing_tenant_b_leads(): void
    {
        $tenantAUser = User::where('email', 'owner@apexdynamics.com')->first();
        $tenantBLead = Lead::whereHas('tenant', fn($q) => $q->where('slug', 'horizon-telecom'))->first();

        $this->assertNotNull($tenantBLead, 'Tenant B lead must exist for test');

        // Tenant A user attempts to read Tenant B lead by direct ID
        $response = $this->actingAs($tenantAUser, 'sanctum')
            ->getJson("/api/v1/leads/{$tenantBLead->id}");

        // Must return 404 Not Found (tenant-isolated query fails)
        $response->assertStatus(404);
    }

    public function test_telecaller_only_receives_assigned_leads_in_lead_list(): void
    {
        $telecaller = User::where('email', 'telecaller@apexdynamics.com')->first();

        $response = $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/leads');

        $response->assertStatus(200);
        $data = $response->json('data');

        // All returned leads must have owner_id == telecaller->id
        foreach ($data as $lead) {
            $this->assertEquals($telecaller->id, $lead['owner_id']);
        }
    }

    public function test_user_permission_override_explicit_deny_wins_over_role_grant(): void
    {
        $admin = User::where('email', 'admin@apexdynamics.com')->first();
        $leadPerm = Permission::where('key', 'leads.export')->first();

        // Initially admin has leads.export
        $this->assertTrue($admin->hasPermission('leads.export'));

        // Apply explicit DENY override
        UserPermissionOverride::create([
            'user_id' => $admin->id,
            'permission_id' => $leadPerm->id,
            'is_granted' => false,
            'reason' => 'Restricted export for this admin account',
        ]);

        // Clear instance cache and check
        $admin = $admin->fresh();
        $this->assertFalse($admin->hasPermission('leads.export'));

        // API request should now be rejected with 403 Forbidden
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/leads/export');

        $response->assertStatus(403);
    }

    public function test_admin_can_create_business_and_import_leads_with_custom_columns(): void
    {
        $admin = User::where('email', 'admin@apexdynamics.com')->first();

        // 1. Create Business with custom columns
        $bizResponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/businesses', [
                'name' => 'Real Estate Luxury',
                'description' => 'Luxury residential real estate campaign',
                'custom_columns' => [
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                    ['key' => 'property_type', 'label' => 'Property Type', 'type' => 'text'],
                    ['key' => 'city', 'label' => 'City', 'type' => 'text'],
                ],
            ]);

        $bizResponse->assertStatus(201)
            ->assertJsonPath('success', true);
        $businessId = $bizResponse->json('data.id');

        // 2. Import CSV containing standard fields + custom columns
        $csvContent = "phone,first_name,last_name,email,budget,property_type,city\n+919988776655,Vikram,Malhotra,vikram@example.com,1.5 Cr,3 BHK Apartment,Gurgaon\n+919988776644,Neha,Kapoor,neha@example.com,2.5 Cr,Villa,Noida";

        $importResponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/leads/import', [
                'business_id' => $businessId,
                'csv_content' => $csvContent,
            ]);

        $importResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.imported_count', 2);

        // Verify lead has business_id and custom_fields saved
        $lead = Lead::where('tenant_id', $admin->tenant_id)->where('phone', '+919988776655')->first();
        $this->assertNotNull($lead);
        $this->assertEquals($businessId, $lead->business_id);
        $this->assertEquals('1.5 Cr', $lead->custom_fields['budget'] ?? null);
        $this->assertEquals('3 BHK Apartment', $lead->custom_fields['property_type'] ?? null);
        $this->assertEquals('Gurgaon', $lead->custom_fields['city'] ?? null);
    }

    public function test_admin_can_bulk_assign_leads_by_range(): void
    {
        $admin = User::where('email', 'admin@apexdynamics.com')->first();
        $telecaller = User::where('email', 'telecaller@apexdynamics.com')->first();

        $biz = \App\Models\Business::create([
            'tenant_id' => $admin->tenant_id,
            'name' => 'Bulk Campaign',
            'slug' => 'bulk-campaign',
        ]);

        // Create 5 unassigned leads for this business
        for ($i = 1; $i <= 5; $i++) {
            Lead::create([
                'tenant_id' => $admin->tenant_id,
                'business_id' => $biz->id,
                'first_name' => "BatchLead_{$i}",
                'phone' => "+91991122330{$i}",
                'status' => 'New',
                'owner_id' => null,
            ]);
        }

        // Bulk assign range 1 to 3 to telecaller for this business
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/leads/bulk-assign', [
                'telecaller_id' => $telecaller->id,
                'business_id' => $biz->id,
                'mode' => 'range',
                'from_index' => 1,
                'to_index' => 3,
                'status' => 'unassigned',
                'reason' => 'Quarterly telecalling queue allocation',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_count', 3);

        $assignedLeads = Lead::where('tenant_id', $admin->tenant_id)
            ->where('business_id', $biz->id)
            ->where('owner_id', $telecaller->id)
            ->where('first_name', 'like', 'BatchLead_%')
            ->get();

        $this->assertCount(3, $assignedLeads);
    }
}
