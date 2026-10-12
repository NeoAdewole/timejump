<?php

namespace App\Policies;

use App\Models\Calendar;
use App\Models\User;

class CalendarPolicy
{
    public function view(User $user, Calendar $calendar): bool
    {
        return (int) $calendar->user_id === (int) $user->id;
    }

    public function update(User $user, Calendar $calendar): bool
    {
        return (int) $calendar->user_id === (int) $user->id;
    }
}
