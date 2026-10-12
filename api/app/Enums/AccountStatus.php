<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Error = 'error';
    case ReauthRequired = 'reauth_required';
}
