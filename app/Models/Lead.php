<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'phone',
        'normalized_phone',
        'email',
        'normalized_email',
        'company',
        'job_title',
        'status',
        'source',
        'owner_id',
        'team_id',
        'estimated_value',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            if ($lead->phone) {
                $lead->normalized_phone = static::normalizePhone($lead->phone);
            }
            if ($lead->email) {
                $lead->normalized_email = static::normalizeEmail($lead->email);
            }
        });
    }

    public static function normalizePhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        $digits = preg_replace('/[^0-9]/', '', $phone);
        // Normalize 10-digit Indian/US phone if prefixed with country code 91 or 1
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    public static function normalizeEmail(?string $email): string
    {
        return $email ? strtolower(trim($email)) : '';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class)->orderBy('called_at', 'desc');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class)->orderBy('due_at', 'asc');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->orderBy('created_at', 'desc');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderBy('created_at', 'desc');
    }
}
