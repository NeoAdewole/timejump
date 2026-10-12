<?php

namespace App\Jobs;

use App\Models\CalendarAccount;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\Exceptions\ReauthorizationRequired;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCalendarAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $uniqueFor = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public CalendarAccount $account) {}

    public function uniqueId(): string
    {
        return (string) $this->account->getKey();
    }

    public function handle(CalendarSyncService $sync): void
    {
        try {
            $sync->syncAccount($this->account);
        } catch (ReauthorizationRequired $e) {
            // Retrying will not help until the user reconnects; the account is already flagged.
            $this->fail($e);
        }
    }
}
