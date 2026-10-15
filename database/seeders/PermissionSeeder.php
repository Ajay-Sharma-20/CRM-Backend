<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['key' => 'dashboard.view_company', 'module' => 'dashboard', 'label' => 'View Company Dashboard', 'risk_level' => 'low'],
            ['key' => 'dashboard.view_team', 'module' => 'dashboard', 'label' => 'View Team Dashboard', 'risk_level' => 'low'],
            ['key' => 'dashboard.view_own', 'module' => 'dashboard', 'label' => 'View Personal Dashboard', 'risk_level' => 'low'],

            // Leads
            ['key' => 'leads.view_all', 'module' => 'leads', 'label' => 'View All Company Leads', 'risk_level' => 'medium'],
            ['key' => 'leads.view_team', 'module' => 'leads', 'label' => 'View Team Leads', 'risk_level' => 'low'],
            ['key' => 'leads.view_assigned', 'module' => 'leads', 'label' => 'View Assigned Leads Only', 'risk_level' => 'low'],
            ['key' => 'leads.create', 'module' => 'leads', 'label' => 'Create New Leads', 'risk_level' => 'low'],
            ['key' => 'leads.update', 'module' => 'leads', 'label' => 'Update Leads', 'risk_level' => 'medium'],
            ['key' => 'leads.delete', 'module' => 'leads', 'label' => 'Delete Leads', 'risk_level' => 'critical'],
            ['key' => 'leads.assign', 'module' => 'leads', 'label' => 'Assign / Reassign Leads', 'risk_level' => 'medium'],
            ['key' => 'leads.bulk_assign', 'module' => 'leads', 'label' => 'Bulk Assign Leads', 'risk_level' => 'high'],
            ['key' => 'leads.import', 'module' => 'leads', 'label' => 'Import Leads from CSV', 'risk_level' => 'high'],
            ['key' => 'leads.export', 'module' => 'leads', 'label' => 'Export Leads to CSV', 'risk_level' => 'high'],

            // Calls
            ['key' => 'calls.view_all', 'module' => 'calls', 'label' => 'View All Call Logs', 'risk_level' => 'medium'],
            ['key' => 'calls.view_team', 'module' => 'calls', 'label' => 'View Team Call Logs', 'risk_level' => 'low'],
            ['key' => 'calls.view_own', 'module' => 'calls', 'label' => 'View Own Call Logs', 'risk_level' => 'low'],
            ['key' => 'calls.log', 'module' => 'calls', 'label' => 'Log Call Outcomes & Notes', 'risk_level' => 'low'],

            // Follow-ups
            ['key' => 'followups.view_all', 'module' => 'followups', 'label' => 'View All Follow-ups', 'risk_level' => 'low'],
            ['key' => 'followups.view_team', 'module' => 'followups', 'label' => 'View Team Follow-ups', 'risk_level' => 'low'],
            ['key' => 'followups.view_own', 'module' => 'followups', 'label' => 'View Own Follow-ups', 'risk_level' => 'low'],
            ['key' => 'followups.create', 'module' => 'followups', 'label' => 'Schedule Follow-ups', 'risk_level' => 'low'],
            ['key' => 'followups.update', 'module' => 'followups', 'label' => 'Update / Reschedule Follow-ups', 'risk_level' => 'low'],
            ['key' => 'followups.complete', 'module' => 'followups', 'label' => 'Complete Follow-ups', 'risk_level' => 'low'],
            ['key' => 'followups.cancel', 'module' => 'followups', 'label' => 'Cancel Follow-ups', 'risk_level' => 'medium'],

            // Tasks
            ['key' => 'tasks.view', 'module' => 'tasks', 'label' => 'View Tasks', 'risk_level' => 'low'],
            ['key' => 'tasks.create', 'module' => 'tasks', 'label' => 'Create Tasks', 'risk_level' => 'low'],
            ['key' => 'tasks.update', 'module' => 'tasks', 'label' => 'Update Tasks', 'risk_level' => 'low'],
            ['key' => 'tasks.assign', 'module' => 'tasks', 'label' => 'Assign Tasks', 'risk_level' => 'medium'],

            // Users & Teams
            ['key' => 'users.view', 'module' => 'users', 'label' => 'View Users', 'risk_level' => 'medium'],
            ['key' => 'users.create', 'module' => 'users', 'label' => 'Create Users', 'risk_level' => 'high'],
            ['key' => 'users.update', 'module' => 'users', 'label' => 'Update Users', 'risk_level' => 'high'],
            ['key' => 'users.deactivate', 'module' => 'users', 'label' => 'Deactivate Users', 'risk_level' => 'critical'],
            ['key' => 'teams.view', 'module' => 'teams', 'label' => 'View Teams', 'risk_level' => 'low'],
            ['key' => 'teams.create', 'module' => 'teams', 'label' => 'Create Teams', 'risk_level' => 'medium'],
            ['key' => 'teams.update', 'module' => 'teams', 'label' => 'Update Teams', 'risk_level' => 'medium'],
            ['key' => 'teams.manage_members', 'module' => 'teams', 'label' => 'Manage Team Members', 'risk_level' => 'high'],

            // Reports
            ['key' => 'reports.view_company', 'module' => 'reports', 'label' => 'View Company Reports', 'risk_level' => 'medium'],
            ['key' => 'reports.view_team', 'module' => 'reports', 'label' => 'View Team Reports', 'risk_level' => 'low'],
            ['key' => 'reports.view_own', 'module' => 'reports', 'label' => 'View Own Reports', 'risk_level' => 'low'],
            ['key' => 'reports.export', 'module' => 'reports', 'label' => 'Export Reports', 'risk_level' => 'high'],

            // System / Access Control / Settings / Audit
            ['key' => 'notifications.view', 'module' => 'notifications', 'label' => 'View Notifications', 'risk_level' => 'low'],
            ['key' => 'settings.view', 'module' => 'settings', 'label' => 'View Workspace Settings', 'risk_level' => 'low'],
            ['key' => 'settings.manage', 'module' => 'settings', 'label' => 'Manage Workspace Settings', 'risk_level' => 'critical'],
            ['key' => 'permissions.view', 'module' => 'permissions', 'label' => 'View Role Permissions', 'risk_level' => 'medium'],
            ['key' => 'permissions.manage', 'module' => 'permissions', 'label' => 'Manage Permissions & Overrides', 'risk_level' => 'critical'],
            ['key' => 'audit.view', 'module' => 'audit', 'label' => 'View Audit Trail', 'risk_level' => 'high'],
        ];

        foreach ($permissions as $p) {
            Permission::updateOrCreate(
                ['key' => $p['key']],
                [
                    'module' => $p['module'],
                    'label' => $p['label'],
                    'description' => $p['label'],
                    'risk_level' => $p['risk_level'],
                    'is_configurable' => true,
                ]
            );
        }
    }
}
