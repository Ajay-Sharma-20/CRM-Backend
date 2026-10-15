<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'user_id',
        'title',
        'due_at',
        'status',
        'notes',
        'completed_at',
        'cancelled_at',
        'cancelled_reason',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'open')->where('due_at', '<', Carbon::now());
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->whereDate('due_at', Carbon::today());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->where('due_at', '>', Carbon::now());
    }
}
