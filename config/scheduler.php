<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core Scheduler Toggles
    |--------------------------------------------------------------------------
    |
    | Enable/disable app-level scheduled commands.
    |
    */
    'horizon_snapshot_enabled' => env('SCHEDULE_HORIZON_SNAPSHOT_ENABLED', true),
    'queue_prune_batches_enabled' => env('SCHEDULE_QUEUE_PRUNE_BATCHES_ENABLED', true),
    'queue_prune_failed_enabled' => env('SCHEDULE_QUEUE_PRUNE_FAILED_ENABLED', true),
];

