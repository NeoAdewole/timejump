<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
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

    public function calendarAccounts(): HasMany
    {
        return $this->hasMany(CalendarAccount::class);
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, Calendar::class);
    }

    /** Access this user has granted to others. */
    public function sharesGiven(): HasMany
    {
        return $this->hasMany(CalendarShare::class, 'owner_id');
    }

    /** Access others have granted to this user. */
    public function sharesReceived(): HasMany
    {
        return $this->hasMany(CalendarShare::class, 'grantee_id');
    }
}
