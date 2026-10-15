<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
        'role_id',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members');
    }

    public function ownedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function isOwner(): bool
    {
        return $this->role?->slug === 'owner';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role?->slug, ['owner', 'admin']);
    }

    public function isManager(): bool
    {
        return $this->role?->slug === 'manager';
    }

    public function isTelecaller(): bool
    {
        return $this->role?->slug === 'telecaller';
    }

    /**
     * Check effective permission based on:
     * 1. User Explicit Deny -> false
     * 2. User Explicit Grant -> true
     * 3. Role Grant -> true
     * 4. Owner role bypass for company admin
     * 5. Default -> false
     */
    public function hasPermission(string $permissionKey): bool
    {
        // 1. Check user override
        $override = $this->permissionOverrides()
            ->whereHas('permission', fn($q) => $q->where('key', $permissionKey))
            ->first();

        if ($override !== null) {
            return (bool) $override->is_granted;
        }

        // 2. Owner has all operational permissions
        if ($this->isOwner()) {
            return true;
        }

        // 3. Check role permissions
        if ($this->role) {
            return $this->role->permissions()
                ->where('key', $permissionKey)
                ->exists();
        }

        return false;
    }

    /**
     * Get all effective permissions for current user
     */
    public function getEffectivePermissions(): array
    {
        $allPermissions = Permission::all();
        $overrides = $this->permissionOverrides()->with('permission')->get()->keyBy('permission.key');
        $rolePermissionKeys = $this->role ? $this->role->permissions->pluck('key')->flip() : collect();

        $effective = [];
        foreach ($allPermissions as $perm) {
            $key = $perm->key;
            if (isset($overrides[$key])) {
                if ($overrides[$key]->is_granted) {
                    $effective[] = $key;
                }
            } elseif ($this->isOwner() || isset($rolePermissionKeys[$key])) {
                $effective[] = $key;
            }
        }

        return $effective;
    }
}
