<?php

namespace App\Services\Calendar\Contracts;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Services\Calendar\Data\CalendarData;
use App\Services\Calendar\Data\EventData;
use Carbon\CarbonImmutable;

/**
 * A source that calendars and events can be imported from.
 */
interface CalendarProvider
{
    /**
     * @return list<CalendarData>
     */
    public function fetchCalendars(CalendarAccount $account): array;

    /**
     * Event instances (recurring series expanded) overlapping [from, to).
     *
     * @return iterable<EventData>
     */
    public function fetchEvents(CalendarAccount $account, Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): iterable;
}
