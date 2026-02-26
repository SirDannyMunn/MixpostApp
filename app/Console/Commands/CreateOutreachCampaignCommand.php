<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LaundryOS\LeadWatcher\Models\Campaign;
use LaundryOS\LeadWatcher\Models\CampaignContact;
use LaundryOS\LeadWatcher\Models\CampaignStep;
use LaundryOS\LeadWatcher\Models\LinkedInAccount;

class CreateOutreachCampaignCommand extends Command
{
    protected $signature = 'outreach:create-campaign
        {org_id : Organization UUID}
        {--account= : LinkedIn account UUID (auto-detected if omitted)}
        {--name=LinkedIn Outreach Campaign : Campaign name}
        {--message=Thanks for connecting! I\'d love to learn more about what you\'re working on. : Message to send after connection accepted}
        {--note= : Optional connection request note (max 300 chars)}
        {--daily-limit=25 : Max connections per day}
        {--wait-days=1 : Days to wait after sending connection before checking}
        {--timeout-days=14 : Days to wait for acceptance before giving up}
        {--limit=0 : Max leads to enroll (0 = all available)}
        {--activate : Immediately activate the campaign}
    ';

    protected $description = 'Create a LinkedIn outreach campaign: connect → wait → check acceptance → message';

    public function handle(): int
    {
        $orgId = $this->argument('org_id');

        // 1. Find or select LinkedIn account
        $accountId = $this->option('account');
        if ($accountId) {
            $account = LinkedInAccount::find($accountId);
        } else {
            $account = LinkedInAccount::where('organization_id', $orgId)
                ->where('status', 'connected')
                ->first();
        }

        if (!$account) {
            $this->error('No connected LinkedIn account found for this organization.');
            $this->info('Available accounts:');
            LinkedInAccount::where('organization_id', $orgId)->each(function ($a) {
                $this->line("  {$a->id} | {$a->username} | {$a->status}");
            });
            return self::FAILURE;
        }

        $this->info("Using LinkedIn account: {$account->username} ({$account->id})");

        // 2. Create campaign
        $campaign = Campaign::create([
            'organization_id' => $orgId,
            'linkedin_account_id' => $account->id,
            'name' => $this->option('name'),
            'description' => 'Auto-generated outreach campaign: Send connection → Wait → Check acceptance → Send message',
            'status' => 'draft',
            'settings' => [
                'daily_connections_limit' => (int) $this->option('daily-limit'),
                'dailyLimit' => (int) $this->option('daily-limit'),
                'minDelay' => 60,
                'maxDelay' => 180,
                'sendWindowStart' => '09:00',
                'sendWindowEnd' => '18:00',
                'timezone' => 'America/New_York',
                'activeDays' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'skipHolidays' => true,
                'trackOpens' => true,
                'trackClicks' => true,
            ],
        ]);

        $this->info("Created campaign: {$campaign->name} ({$campaign->id})");

        // 3. Create the 4 steps
        $note = $this->option('note');
        $message = $this->option('message');
        $waitDays = (int) $this->option('wait-days');
        $timeoutDays = (int) $this->option('timeout-days');

        // Step 1: Send connection request
        $step1 = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'order' => 1,
            'type' => CampaignStep::TYPE_INVITATION,
            'action_type' => 'send_invitation',
            'message_template' => $note,
            'config' => [
                'includeNote' => !empty($note),
                'note' => $note,
            ],
        ]);
        $this->line("  Step 1: Send connection request" . ($note ? " (with note)" : ""));

        // Step 2: Wait
        $step2 = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'order' => 2,
            'type' => CampaignStep::TYPE_WAIT,
            'delay_days' => $waitDays,
            'delay_hours' => 0,
            'config' => [
                'days' => $waitDays,
                'hours' => 0,
            ],
        ]);
        $this->line("  Step 2: Wait {$waitDays} day(s)");

        // Step 3: Condition — check acceptance
        $step3 = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'order' => 3,
            'type' => CampaignStep::TYPE_CONDITION,
            'condition_type' => CampaignStep::CONDITION_ACCEPTED,
            'condition_config' => [
                'type' => 'accepted',
                'timeout_days' => $timeoutDays,
            ],
        ]);
        $this->line("  Step 3: Check acceptance (timeout: {$timeoutDays} days)");

        // Step 4: Send message
        $step4 = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'order' => 4,
            'type' => CampaignStep::TYPE_MESSAGE,
            'action_type' => 'send_message',
            'message_template' => $message,
            'config' => [
                'message' => $message,
            ],
        ]);
        $this->line("  Step 4: Send message");

        // 4. Enroll leads
        $limit = (int) $this->option('limit');

        $query = DB::table('lw_lead_organization')
            ->join('lw_leads', 'lw_leads.id', '=', 'lw_lead_organization.lead_id')
            ->where('lw_lead_organization.organization_id', $orgId)
            ->whereNotNull('lw_leads.profile_url')
            ->where('lw_leads.profile_url', '!=', '')
            ->where('lw_leads.primary_platform', 'linkedin')
            ->select('lw_leads.id as lead_id', 'lw_leads.profile_url', 'lw_leads.display_name');

        // Exclude leads already in any active campaign for this org
        $existingLeadIds = CampaignContact::whereHas('campaign', function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)->whereIn('status', ['active', 'draft']);
        })->pluck('lead_id');

        if ($existingLeadIds->isNotEmpty()) {
            $query->whereNotIn('lw_leads.id', $existingLeadIds);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $leads = $query->get();

        if ($leads->isEmpty()) {
            $this->warn('No eligible leads found with LinkedIn profiles in this organization.');
            $this->warn('Campaign created with steps but no contacts. Add contacts manually or via the UI.');
        } else {
            $enrolled = 0;
            foreach ($leads as $lead) {
                CampaignContact::create([
                    'campaign_id' => $campaign->id,
                    'lead_id' => $lead->lead_id,
                    'linkedin_profile_url' => $lead->profile_url,
                    'status' => CampaignContact::STATUS_PENDING,
                    'enrolled_at' => now(),
                ]);
                $enrolled++;
            }
            $campaign->update(['total_contacts' => $enrolled]);
            $this->info("Enrolled {$enrolled} leads");
        }

        // 5. Optionally activate
        if ($this->option('activate')) {
            if ($campaign->canBeStarted()) {
                $campaign->start();
                $this->info('Campaign activated! It will start processing on the next scheduler run.');
            } else {
                $this->warn('Campaign cannot be activated (needs steps + contacts). Set to draft.');
            }
        } else {
            $this->info('Campaign is in draft mode. Activate with:');
            $this->line("  php artisan tinker --execute=\"LaundryOS\\LeadWatcher\\Models\\Campaign::find('{$campaign->id}')->start()\"");
            $this->line('  Or pass --activate when running this command.');
        }

        // Summary
        $this->newLine();
        $this->info('=== Campaign Summary ===');
        $this->table(
            ['Property', 'Value'],
            [
                ['Campaign ID', $campaign->id],
                ['Name', $campaign->name],
                ['Status', $campaign->status],
                ['LinkedIn Account', "{$account->username} ({$account->id})"],
                ['Contacts', $campaign->total_contacts],
                ['Daily Limit', $this->option('daily-limit')],
                ['Steps', '4 (connect → wait → check → message)'],
            ]
        );

        $this->info('Flow: Send connection → Wait ' . $waitDays . 'd → Check acceptance daily (up to ' . $timeoutDays . 'd) → Send message');

        return self::SUCCESS;
    }
}
