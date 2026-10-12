<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_event_id',
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'is_all_day',
        'timezone',
        'status',
        'is_busy',
        'visibility',
        'organizer_email',
        'url',
        'recurring_event_id',
        'sync_batch',
    ];

    protected $hidden = ['sync_batch'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_all_day' => 'boolean',
            'is_busy' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function isPrivate(): bool
    {
        return in_array($this->visibility, ['private', 'confidential'], true);
    }

    /**
     * Events that overlap the [start, end) window.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->where('events.starts_at', '<', $end->copy()->utc())
            ->where('events.ends_at', '>', $start->copy()->utc());
    }
}
