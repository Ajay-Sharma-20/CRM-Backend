<?php

namespace Database\Seeders;

use App\Models\AuditEvent;
use App\Models\CallLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadNote;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CrmDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create or Find Tenants
        $tenantA = Tenant::firstOrCreate(
            ['slug' => 'apex-dynamics'],
            [
                'name' => 'Apex Dynamics CRM',
                'timezone' => 'UTC',
                'status' => 'active',
            ]
        );

        $tenantB = Tenant::firstOrCreate(
            ['slug' => 'horizon-telecom'],
            [
                'name' => 'Horizon Telecom Inc.',
                'timezone' => 'UTC',
                'status' => 'active',
            ]
        );

        // 2. Create Roles for Tenant A
        $ownerRole = Role::firstOrCreate(
            ['tenant_id' => $tenantA->id, 'slug' => 'owner'],
            ['name' => 'Company Owner', 'description' => 'Full workspace authority and administrative control', 'is_system' => true]
        );

        $adminRole = Role::firstOrCreate(
            ['tenant_id' => $tenantA->id, 'slug' => 'admin'],
            ['name' => 'Administrator', 'description' => 'Team, lead, settings, and operational management', 'is_system' => true]
        );

        $managerRole = Role::firstOrCreate(
            ['tenant_id' => $tenantA->id, 'slug' => 'manager'],
            ['name' => 'Sales Manager', 'description' => 'Team-scoped lead assignment and performance monitoring', 'is_system' => true]
        );

        $telecallerRole = Role::firstOrCreate(
            ['tenant_id' => $tenantA->id, 'slug' => 'telecaller'],
            ['name' => 'Telecaller', 'description' => 'Assigned lead execution, calling, outcome logging, and follow-ups', 'is_system' => true]
        );

        // Roles for Tenant B
        $ownerRoleB = Role::firstOrCreate(
            ['tenant_id' => $tenantB->id, 'slug' => 'owner'],
            ['name' => 'Company Owner', 'description' => 'Tenant B Owner', 'is_system' => true]
        );
        $telecallerRoleB = Role::firstOrCreate(
            ['tenant_id' => $tenantB->id, 'slug' => 'telecaller'],
            ['name' => 'Telecaller', 'description' => 'Tenant B Telecaller', 'is_system' => true]
        );

        // 3. Assign Permissions to Roles
        $allPerms = Permission::all();

        // Owner gets all
        $ownerRole->permissions()->sync($allPerms->pluck('id'));
        $ownerRoleB->permissions()->sync($allPerms->pluck('id'));

        // Admin permissions
        $adminPermKeys = [
            'dashboard.view_company', 'dashboard.view_team', 'dashboard.view_own',
            'leads.view_all', 'leads.view_team', 'leads.view_assigned', 'leads.create', 'leads.update', 'leads.delete', 'leads.assign', 'leads.bulk_assign', 'leads.import', 'leads.export',
            'calls.view_all', 'calls.view_team', 'calls.view_own', 'calls.log',
            'followups.view_all', 'followups.view_team', 'followups.view_own', 'followups.create', 'followups.update', 'followups.complete', 'followups.cancel',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.assign',
            'users.view', 'users.create', 'users.update', 'users.deactivate',
            'teams.view', 'teams.create', 'teams.update', 'teams.manage_members',
            'reports.view_company', 'reports.view_team', 'reports.view_own', 'reports.export',
            'notifications.view', 'settings.view', 'settings.manage', 'permissions.view', 'permissions.manage', 'audit.view'
        ];
        $adminRole->permissions()->sync(
            $allPerms->whereIn('key', $adminPermKeys)->pluck('id')
        );

        // Manager permissions
        $managerPermKeys = [
            'dashboard.view_team', 'dashboard.view_own',
            'leads.view_team', 'leads.view_assigned', 'leads.create', 'leads.update', 'leads.assign',
            'calls.view_team', 'calls.view_own', 'calls.log',
            'followups.view_team', 'followups.view_own', 'followups.create', 'followups.update', 'followups.complete', 'followups.cancel',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.assign',
            'teams.view',
            'reports.view_team', 'reports.view_own',
            'notifications.view'
        ];
        $managerRole->permissions()->sync(
            $allPerms->whereIn('key', $managerPermKeys)->pluck('id')
        );

        // Telecaller permissions
        $telecallerPermKeys = [
            'dashboard.view_own',
            'leads.view_assigned', 'leads.create', 'leads.update',
            'calls.view_own', 'calls.log',
            'followups.view_own', 'followups.create', 'followups.update', 'followups.complete',
            'tasks.view', 'tasks.create', 'tasks.update',
            'reports.view_own',
            'notifications.view'
        ];
        $telecallerRole->permissions()->sync(
            $allPerms->whereIn('key', $telecallerPermKeys)->pluck('id')
        );
        $telecallerRoleB->permissions()->sync(
            $allPerms->whereIn('key', $telecallerPermKeys)->pluck('id')
        );

        // 4. Create Users
        $defaultPassword = Hash::make('Password123!');

        $owner = User::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'email' => 'owner@apexdynamics.com'],
            ['name' => 'Siddharth Rao', 'phone' => '+919876543210', 'password' => $defaultPassword, 'role_id' => $ownerRole->id, 'status' => 'active']
        );

        $admin = User::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'email' => 'admin@apexdynamics.com'],
            ['name' => 'Priya Patel', 'phone' => '+919876543211', 'password' => $defaultPassword, 'role_id' => $adminRole->id, 'status' => 'active']
        );

        $manager = User::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'email' => 'manager@apexdynamics.com'],
            ['name' => 'Vikram Malhotra', 'phone' => '+919876543212', 'password' => $defaultPassword, 'role_id' => $managerRole->id, 'status' => 'active']
        );

        $telecaller = User::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'email' => 'telecaller@apexdynamics.com'],
            ['name' => 'Ananya Sen', 'phone' => '+919876543213', 'password' => $defaultPassword, 'role_id' => $telecallerRole->id, 'status' => 'active']
        );

        $telecaller2 = User::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'email' => 'telecaller2@apexdynamics.com'],
            ['name' => 'Rahul Verma', 'phone' => '+919876543214', 'password' => $defaultPassword, 'role_id' => $telecallerRole->id, 'status' => 'active']
        );

        // Tenant B Users
        $ownerB = User::updateOrCreate(
            ['tenant_id' => $tenantB->id, 'email' => 'owner@horizon.com'],
            ['name' => 'Marcus Vance', 'phone' => '+14155552671', 'password' => $defaultPassword, 'role_id' => $ownerRoleB->id, 'status' => 'active']
        );

        $telecallerB = User::updateOrCreate(
            ['tenant_id' => $tenantB->id, 'email' => 'telecaller@horizon.com'],
            ['name' => 'Elena Rostova', 'phone' => '+14155552672', 'password' => $defaultPassword, 'role_id' => $telecallerRoleB->id, 'status' => 'active']
        );

        // 5. Teams
        $alphaTeam = Team::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'name' => 'Alpha Outbound Sales'],
            ['description' => 'Targeting high-value B2B SaaS accounts across India', 'leader_id' => $manager->id]
        );
        $alphaTeam->members()->syncWithoutDetaching([$telecaller->id, $telecaller2->id]);

        $inboundTeam = Team::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'name' => 'Inbound Retention'],
            ['description' => 'Handling product trials and contact requests', 'leader_id' => $manager->id]
        );

        // 6. Tenant Settings
        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'key' => 'lead_statuses'],
            ['value' => ['New', 'Assigned', 'Contacted', 'Interested', 'Follow-up', 'Qualified', 'Converted', 'Lost', 'Invalid']]
        );

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'key' => 'call_outcomes'],
            ['value' => [
                'Connected', 'Interested', 'Follow-up Required', 'Call Later', 'Busy',
                'No Answer', 'Not Reachable', 'Wrong Number', 'Not Interested',
                'Demo/Meeting Scheduled', 'Converted', 'Lost'
            ]]
        );

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantA->id, 'key' => 'lead_sources'],
            ['value' => ['Website', 'Referral', 'Cold Call', 'Campaign', 'Inbound', 'Social Media', 'Events']]
        );

        // 7. Seed Sample Leads for Tenant A
        $leadsData = [
            [
                'first_name' => 'Rohan', 'last_name' => 'Mehta', 'phone' => '+919820011223',
                'email' => 'rohan.mehta@nexuscorp.in', 'company' => 'Nexus Corp', 'job_title' => 'VP Technology',
                'status' => 'Follow-up', 'source' => 'Website', 'owner_id' => $telecaller->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 85000.00, 'notes' => 'Looking for 40 seat CRM deployment by next quarter.'
            ],
            [
                'first_name' => 'Pooja', 'last_name' => 'Sharma', 'phone' => '+919811223344',
                'email' => 'pooja@fintechinnovate.com', 'company' => 'Fintech Innovate', 'job_title' => 'Sales Director',
                'status' => 'Interested', 'source' => 'Campaign', 'owner_id' => $telecaller->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 120000.00, 'notes' => 'Wants automated telecalling logging and daily team metrics.'
            ],
            [
                'first_name' => 'Arjun', 'last_name' => 'Kapoor', 'phone' => '+919833445566',
                'email' => 'arjun.k@brightcloud.io', 'company' => 'BrightCloud Software', 'job_title' => 'Operations Head',
                'status' => 'Contacted', 'source' => 'Cold Call', 'owner_id' => $telecaller->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 45000.00, 'notes' => 'Brief discussion held. Asked to call back on Friday morning.'
            ],
            [
                'first_name' => 'Neha', 'last_name' => 'Gupta', 'phone' => '+919844556677',
                'email' => 'neha@solarislogistics.com', 'company' => 'Solaris Logistics', 'job_title' => 'Managing Partner',
                'status' => 'New', 'source' => 'Inbound', 'owner_id' => $telecaller2->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 60000.00, 'notes' => 'Downloaded product overview whitepaper.'
            ],
            [
                'first_name' => 'Karan', 'last_name' => 'Singhania', 'phone' => '+919855667788',
                'email' => 'karan@apexretail.org', 'company' => 'Apex Retail Group', 'job_title' => 'Head of Growth',
                'status' => 'Qualified', 'source' => 'Referral', 'owner_id' => $telecaller->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 210000.00, 'notes' => 'Budget approved. Needs customized SLA contract.'
            ],
            [
                'first_name' => 'Deepak', 'last_name' => 'Verma', 'phone' => '+919866778899',
                'email' => 'deepak@vermaholdings.com', 'company' => 'Verma Holdings', 'job_title' => 'Founder & CEO',
                'status' => 'Converted', 'source' => 'Website', 'owner_id' => $telecaller->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 150000.00, 'notes' => 'Signed 1-year annual contract.'
            ],
            [
                'first_name' => 'Sunita', 'last_name' => 'Nair', 'phone' => '+919877889900',
                'email' => 'sunita@bluestarpharma.com', 'company' => 'BlueStar Pharma', 'job_title' => 'Procurement Lead',
                'status' => 'Lost', 'source' => 'Campaign', 'owner_id' => $telecaller2->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 30000.00, 'notes' => 'Decided to renew existing legacy system.'
            ],
            [
                'first_name' => 'Manish', 'last_name' => 'Trivedi', 'phone' => '+919888990011',
                'email' => 'manish@edutechfuture.com', 'company' => 'EduTech Future', 'job_title' => 'Principal Consultant',
                'status' => 'Assigned', 'source' => 'Website', 'owner_id' => $telecaller2->id, 'team_id' => $alphaTeam->id,
                'estimated_value' => 40000.00, 'notes' => 'Assigned to Rahul Verma for first touch.'
            ],
            [
                'first_name' => 'Zoya', 'last_name' => 'Akhtar', 'phone' => '+919899001122',
                'email' => 'zoya@creativematrix.in', 'company' => 'Creative Matrix Studios', 'job_title' => 'Creative Director',
                'status' => 'New', 'source' => 'Events', 'owner_id' => null, 'team_id' => null,
                'estimated_value' => 50000.00, 'notes' => 'Met at Delhi SaaS Summit.'
            ],
        ];

        foreach ($leadsData as $ld) {
            $lead = Lead::updateOrCreate(
                ['tenant_id' => $tenantA->id, 'phone' => $ld['phone']],
                array_merge($ld, [
                    'tenant_id' => $tenantA->id,
                    'created_by' => $owner->id,
                ])
            );

            // Add creation activity
            LeadActivity::create([
                'tenant_id' => $tenantA->id,
                'lead_id' => $lead->id,
                'user_id' => $owner->id,
                'type' => 'created',
                'description' => "Lead {$lead->first_name} {$lead->last_name} created via {$lead->source}.",
            ]);

            // Add note
            if ($lead->notes) {
                LeadNote::create([
                    'tenant_id' => $tenantA->id,
                    'lead_id' => $lead->id,
                    'user_id' => $owner->id,
                    'content' => $lead->notes,
                ]);
            }
        }

        // 8. Add Call Logs & Follow-ups for Rohan Mehta (Assigned to Ananya)
        $rohan = Lead::where('tenant_id', $tenantA->id)->where('first_name', 'Rohan')->first();
        if ($rohan) {
            // Past call
            CallLog::create([
                'tenant_id' => $tenantA->id,
                'lead_id' => $rohan->id,
                'user_id' => $telecaller->id,
                'outcome' => 'Follow-up Required',
                'duration_seconds' => 184,
                'notes' => 'Rohan discussed their current 40-agent telecalling team pain points. Needs a demo scheduled.',
                'called_at' => Carbon::now()->subDays(1),
            ]);

            LeadActivity::create([
                'tenant_id' => $tenantA->id,
                'lead_id' => $rohan->id,
                'user_id' => $telecaller->id,
                'type' => 'call_logged',
                'description' => 'Outbound call logged. Outcome: Follow-up Required (Duration: 3m 4s).',
            ]);

            // Follow-up due today
            FollowUp::create([
                'tenant_id' => $tenantA->id,
                'lead_id' => $rohan->id,
                'user_id' => $telecaller->id,
                'title' => 'Product Demo Walkthrough',
                'due_at' => Carbon::now()->addHours(2),
                'status' => 'open',
                'notes' => 'Present the mobile PWA telecalling workflow and manager reporting.',
            ]);
        }

        // Add Overdue Follow-up for Arjun Kapoor
        $arjun = Lead::where('tenant_id', $tenantA->id)->where('first_name', 'Arjun')->first();
        if ($arjun) {
            FollowUp::create([
                'tenant_id' => $tenantA->id,
                'lead_id' => $arjun->id,
                'user_id' => $telecaller->id,
                'title' => 'Follow-up on Proposal Review',
                'due_at' => Carbon::now()->subDays(2),
                'status' => 'open',
                'notes' => 'Check if executive committee approved the quote.',
            ]);
        }

        // 9. Tenant B Sample Lead (for isolation testing)
        $leadB = Lead::updateOrCreate(
            ['tenant_id' => $tenantB->id, 'phone' => '+14159998877'],
            [
                'tenant_id' => $tenantB->id,
                'first_name' => 'Secret',
                'last_name' => 'Client-TenantB',
                'phone' => '+14159998877',
                'email' => 'secret@tenantb.com',
                'company' => 'Tenant B Confidential Corp',
                'status' => 'New',
                'source' => 'Inbound',
                'owner_id' => $telecallerB->id,
                'estimated_value' => 999999.00,
                'notes' => 'This record must never be visible to Tenant A users!',
                'created_by' => $ownerB->id,
            ]
        );

        // 10. Sample Notifications for Ananya Sen (Telecaller)
        Notification::create([
            'tenant_id' => $tenantA->id,
            'user_id' => $telecaller->id,
            'title' => 'New Lead Assigned',
            'message' => 'Rohan Mehta (Nexus Corp) has been assigned to you by Vikram Malhotra.',
            'type' => 'assignment',
            'link' => '/leads/' . ($rohan?->id ?? 1),
            'is_read' => false,
        ]);

        Notification::create([
            'tenant_id' => $tenantA->id,
            'user_id' => $telecaller->id,
            'title' => 'Overdue Follow-up Alert',
            'message' => 'Follow-up with Arjun Kapoor (BrightCloud Software) is 2 days overdue.',
            'type' => 'overdue',
            'link' => '/follow-ups',
            'is_read' => false,
        ]);

        // 11. Initial Audit Event
        AuditEvent::log(
            $tenantA->id,
            $owner->id,
            'tenant_initialized',
            'Tenant',
            $tenantA->id,
            'Apex Dynamics CRM workspace initialized with default roles, permissions, and teams.'
        );
    }
}
