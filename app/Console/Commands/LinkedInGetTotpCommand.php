<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use LaundryOS\LeadWatcher\Models\LinkedInAccount;

class LinkedInGetTotpCommand extends Command
{
    protected $signature = 'linkedin:get-totp {email : The email/username of the LinkedIn account}';

    protected $description = 'Generate a TOTP code for a LinkedIn account';

    public function handle(): int
    {
        $email = $this->argument('email');

        $account = LinkedInAccount::where('username', $email)->first();

        if (!$account) {
            $this->error("No LinkedIn account found with email: {$email}");
            $this->newLine();
            $this->info('Available accounts:');
            
            LinkedInAccount::all()->each(function ($acc) {
                $this->line("  - {$acc->username}");
            });
            
            return self::FAILURE;
        }

        if (!$account->totp_secret) {
            $this->error("No TOTP secret configured for account: {$email}");
            return self::FAILURE;
        }

        $totpCode = $account->generateTotpCode();

        if (!$totpCode) {
            $this->error("Failed to generate TOTP code for account: {$email}");
            return self::FAILURE;
        }

        $secondsRemaining = 30 - (time() % 30);

        $this->newLine();
        $this->info("TOTP Code for {$email}:");
        $this->newLine();
        $this->line("  <fg=green;options=bold>{$totpCode}</>");
        $this->newLine();
        $this->comment("  Valid for {$secondsRemaining} more seconds");
        $this->newLine();

        return self::SUCCESS;
    }
}
