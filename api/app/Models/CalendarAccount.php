<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CalendarAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'provider_account_id',
        'email',
        'display_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'source_url',
        'status',
        'last_error',
        'last_synced_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => AccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'source_url' => 'encrypted',
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Uploaded .ics files live on disk; clean them up with the account.
        static::deleted(function (CalendarAccount $account) {
            Storage::disk('local')->delete($account->icalStoragePath());
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    public function icalStoragePath(): string
    {
        return "ical/{$this->getKey()}.ics";
    }
}
