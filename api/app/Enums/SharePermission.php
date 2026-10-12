<?php

namespace App\Enums;

enum SharePermission: string
{
    /** The grantee only sees when the owner is busy (merged time blocks, no event data). */
    case FreeBusy = 'free_busy';

    /** The grantee sees full event details (private events are still masked). */
    case Details = 'details';
}
