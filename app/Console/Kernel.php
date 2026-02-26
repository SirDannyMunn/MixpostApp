<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        \Inovector\Mixpost\Schedule::register($schedule);

        if (config('scheduler.horizon_snapshot_enabled', true)) {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
        }

        if (config('scheduler.queue_prune_batches_enabled', true)) {
            $schedule->command('queue:prune-batches')->daily();
        }

        if (config('scheduler.queue_prune_failed_enabled', true)) {
            $schedule->command('queue:prune-failed')->daily();
        }

        $schedule->command('browser-use:sync-webshare-proxies')->everyFifteenMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
