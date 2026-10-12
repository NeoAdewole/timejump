<?php

namespace App\Policies;

use App\Models\CalendarShare;
use App\Models\User;

class CalendarSharePolicy
{
    /** Only the owner can see, change or revoke a share they granted. */
    public function view(User $user, CalendarShare $share): bool
    {
        return (int) $share->owner_id === (int) $user->id;
    }

    public function update(User $user, CalendarShare $share): bool
    {
        return (int) $share->owner_id === (int) $user->id;
    }

    public function delete(User $user, CalendarShare $share): bool
    {
        return (int) $share->owner_id === (int) $user->id;
    }

    /** A grantee may remove a share from their own "shared with me" list. */
    public function leave(User $user, CalendarShare $share): bool
    {
        return (int) $share->grantee_id === (int) $user->id;
    }
}
