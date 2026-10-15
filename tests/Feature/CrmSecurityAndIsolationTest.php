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

    public function test_duplicate_lead_phone_warning_returned(): void
    {
        $owner = User::where('email', 'owner@apexdynamics.com')->first();
        $existingLead = Lead::where('tenant_id', $owner->tenant_id)->first();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/leads', [
                'first_name' => 'Duplicate',
                'last_name' => 'Test',
                'phone' => $existingLead->phone, // duplicate phone
                'status' => 'New',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('is_duplicate', true);
    }
}
