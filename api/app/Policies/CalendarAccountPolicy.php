<?php

namespace App\Policies;

use App\Models\CalendarAccount;
use App\Models\User;

class CalendarAccountPolicy
{
    public function view(User $user, CalendarAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }

    public function sync(User $user, CalendarAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }

    public function delete(User $user, CalendarAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }
}
