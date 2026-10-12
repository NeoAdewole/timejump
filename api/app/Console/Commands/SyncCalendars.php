<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Jobs\SyncCalendarAccount;
use App\Models\CalendarAccount;
use App\Services\Calendar\CalendarSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncCalendars extends Command
{
    protected $signature = 'timejump:sync
        {--account=* : Only sync these calendar account IDs}
        {--now : Sync in this process instead of queueing jobs}';

    protected $description = 'Import the latest calendars and events from every connected account';

    public function handle(CalendarSyncService $sync): int
    {
        $accounts = CalendarAccount::query()
            ->where('status', '!=', AccountStatus::ReauthRequired->value)
            ->when($this->option('account'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        foreach ($accounts as $account) {
            if (! $this->option('now')) {
                SyncCalendarAccount::dispatch($account);

                continue;
            }

            try {
                $result = $sync->syncAccount($account);
                $this->info("Account #{$account->id} ({$account->provider->value}): {$result['calendars']} calendars, {$result['events']} events.");
            } catch (Throwable $e) {
                $this->error("Account #{$account->id} failed: {$e->getMessage()}");
            }
        }

        if (! $this->option('now')) {
            $this->info("Queued {$accounts->count()} account sync job(s).");
        }

        return self::SUCCESS;
    }
}
