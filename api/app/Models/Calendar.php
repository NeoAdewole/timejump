<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Calendar extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_calendar_id',
        'name',
        'description',
        'color',
        'timezone',
        'is_primary',
        'is_read_only',
        'is_enabled',
        'custom_name',
        'custom_color',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_read_only' => 'boolean',
            'is_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CalendarAccount::class, 'calendar_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function displayName(): string
    {
        return $this->custom_name ?: $this->name;
    }

    public function displayColor(): ?string
    {
        return $this->custom_color ?: $this->color;
    }
}
