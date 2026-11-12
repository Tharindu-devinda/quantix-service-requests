<?php

namespace App\Models;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'category',
        'priority',
        'status',
        'requester_id',
        'assigned_to',
        'submitted_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'priority' => 'medium',
    ];

    protected function casts(): array
    {
        return [
            'category' => RequestCategory::class,
            'priority' => RequestPriority::class,
            'status' => RequestStatus::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(RequestComment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(RequestActivity::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            UserRole::Employee => $query->where('requester_id', $user->id),

            UserRole::Manager => $query
                ->whereIn('requester_id', $user->directReports()->select('id'))
                ->where('status', '!=', RequestStatus::Draft->value),

            UserRole::Operations => $query->whereIn('status', [
                RequestStatus::Approved->value,
                RequestStatus::Assigned->value,
                RequestStatus::InProgress->value,
                RequestStatus::Completed->value,
            ]),
        };
    }
}
