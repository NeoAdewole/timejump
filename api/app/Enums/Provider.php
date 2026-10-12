<?php

namespace App\Enums;

enum Provider: string
{
    case Google = 'google';
    case Outlook = 'outlook';
    case Ical = 'ical';

    public function usesOAuth(): bool
    {
        return $this !== self::Ical;
    }

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Calendar',
            self::Outlook => 'Outlook / Microsoft 365',
            self::Ical => 'iCal (ICS) feed',
        };
    }

    /**
     * @return list<string>
     */
    public static function oauthValues(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->usesOAuth())
        ));
    }
}
