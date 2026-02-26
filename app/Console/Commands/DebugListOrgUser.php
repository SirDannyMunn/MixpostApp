<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DebugListOrgUser extends Command
{
    protected $signature = 'dev:ids:list';

    protected $aliases = [
        'dev:list-ids',
    ];
    protected $description = 'List first organization and user UUIDs to use for testing';

    public function handle(): int
    {
        $org = \App\Models\Organization::query()->first();
        $user = \App\Models\User::query()->first();
        if (!$org) {
            $this->warn('No organization found');
        } else {
            $this->line('Organization: ' . $org->id . '  name=' . $org->name);
        }
        if (!$user) {
            $this->warn('No user found');
        } else {
            $this->line('User: ' . $user->id . '  name=' . $user->name);
        }
        return 0;
    }
}
