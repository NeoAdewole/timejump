<?php

use Illuminate\Support\Facades\Schedule;

// Keep imported calendars fresh.
Schedule::command('timejump:sync')->everyFifteenMinutes()->withoutOverlapping();

// Housekeeping for expired Sanctum tokens.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
