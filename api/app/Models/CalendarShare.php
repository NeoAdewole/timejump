<?php

namespace App\Models;

use App\Enums\SharePermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CalendarShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'grantee_id',
        'permission',
        'all_calendars',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'permission' => SharePermission::class,
            'all_calendars' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_id');
    }

    public function calendars(): BelongsToMany
    {
        return $this->belongsToMany(Calendar::class, 'calendar_share_calendar');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function allowsDetails(): bool
    {
        return $this->permission === SharePermission::Details;
    }
}
