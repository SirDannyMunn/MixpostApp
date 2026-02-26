<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestEmailCommand extends Command
{
    protected $signature = 'test:email
        {email : The email address to send to}
        {--mailer= : Which mailable to send (leave blank to choose interactively)}';

    protected $description = 'Send a test email using any registered mailable';

    /**
     * Registry of available mailables with factory closures that create them with dummy data.
     */
    protected function getMailables(): array
    {
        return [
            'agent-run-completed' => [
                'class' => \LaundryOS\LeadDiscovery\Mail\AgentRunCompletedMail::class,
                'description' => 'Agent run completed notification (lead discovery)',
                'factory' => function () {
                    $agent = \LaundryOS\LeadDiscovery\Models\Agent::latest()->first();
                    $run = $agent?->runs()->latest()->first();

                    if (!$agent || !$run) {
                        // Create fake instances for preview
                        $agent = new \LaundryOS\LeadDiscovery\Models\Agent([
                            'name' => 'Test Agent',
                        ]);
                        $agent->id = 'test-agent-id';

                        $run = new \LaundryOS\LeadDiscovery\Models\AgentRun([
                            'leads_created' => 12,
                            'leads_updated' => 3,
                            'leads_skipped' => 5,
                            'total_results' => 20,
                            'signals_generated' => 8,
                            'duration_seconds' => 245,
                            'completed_at' => now(),
                        ]);
                        $run->id = 'test-run-id';
                    }

                    return new \LaundryOS\LeadDiscovery\Mail\AgentRunCompletedMail($agent, $run);
                },
            ],
            'account-unauthorized' => [
                'class' => \Inovector\Mixpost\Mail\AccountUnauthorizedMail::class,
                'description' => 'Social account unauthorized notification',
                'factory' => function () {
                    $account = \Inovector\Mixpost\Models\Account::first();

                    if (!$account) {
                        $this->error('No Account records found. Cannot build this mailable without one.');
                        return null;
                    }

                    return new \Inovector\Mixpost\Mail\AccountUnauthorizedMail($account);
                },
            ],
        ];
    }

    public function handle(): int
    {
        $email = $this->argument('email');
        $mailables = $this->getMailables();
        $mailerKey = $this->option('mailer');

        if (!$mailerKey) {
            $choices = [];
            foreach ($mailables as $key => $config) {
                $choices[$key] = "{$key} — {$config['description']}";
            }

            $mailerKey = $this->choice(
                'Which mailable do you want to send?',
                array_keys($choices),
                0
            );
        }

        if (!isset($mailables[$mailerKey])) {
            // Try partial match
            $matches = array_filter(array_keys($mailables), fn ($k) => str_contains($k, $mailerKey));
            if (count($matches) === 1) {
                $mailerKey = reset($matches);
            } else {
                $this->error("Unknown mailable: {$mailerKey}");
                $this->line('Available: ' . implode(', ', array_keys($mailables)));
                return self::FAILURE;
            }
        }

        $config = $mailables[$mailerKey];
        $this->info("Building mailable: {$config['description']}");

        $mailable = ($config['factory'])();

        if (!$mailable) {
            return self::FAILURE;
        }

        $this->info("Sending {$mailerKey} to {$email}...");

        try {
            Mail::mailer('resend')->to($email)->send($mailable);
            $this->info("✓ Email sent successfully to {$email}");
        } catch (\Throwable $e) {
            $this->error("✗ Failed to send: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
