<?php

namespace App\Console\Commands;

use App\Jobs\RebuildVoiceProfileJob;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Voice\VoiceProfileBuilderService;
use Illuminate\Console\Command;

class VoiceRebuild extends Command
{
    protected $signature = 'voice:rebuild '
        . '{--user= : User id or email to find voice profile for}'
        . ' {--profile= : Existing voice profile id to rebuild}'
        . ' {--all : Rebuild all voice profiles for the user}'
        . ' {--sync : Run synchronously instead of queueing}'
        . ' {--source-id= : Filter posts by source_id}'
        . ' {--min-engagement= : Minimum engagement score}'
        . ' {--start-date= : Start date filter (Y-m-d)}'
        . ' {--end-date= : End date filter (Y-m-d)}'
        . ' {--exclude-replies : Exclude reply posts}'
        . ' {--limit= : Max posts to use for rebuild}'
        . ' {--schema-version=2.0 : Schema version (1.0 or 2.0)}';

    protected $description = 'Rebuild a voice profile from the command line. Provide either --user or --profile.';

    public function handle(): int
    {
        $userInput = $this->option('user') ? trim((string) $this->option('user')) : '';
        $profileId = $this->option('profile') ? trim((string) $this->option('profile')) : '';
        $rebuildAll = (bool) $this->option('all');
        $sync = (bool) $this->option('sync');

        // Build filters array
        $filters = $this->buildFilters();

        // Collect profiles to rebuild
        $profiles = collect();

        if ($profileId !== '') {
            // Direct profile id provided
            $profile = VoiceProfile::query()
                ->where('id', $profileId)
                ->whereNull('deleted_at')
                ->first();

            if (!$profile) {
                $this->error("Voice profile not found: {$profileId}");
                return self::FAILURE;
            }

            $profiles->push($profile);
        } elseif ($userInput !== '') {
            // Find user by id or email
            $user = User::query()
                ->where('id', $userInput)
                ->orWhere('email', $userInput)
                ->first();

            if (!$user) {
                $this->error("User not found: {$userInput}");
                return self::FAILURE;
            }

            $this->info("Found user: {$user->email} (ID: {$user->id})");

            // Get voice profiles for this user
            $query = VoiceProfile::query()
                ->where('user_id', $user->id)
                ->whereNull('deleted_at');

            if (!$rebuildAll) {
                // Get only the default profile, or first one if no default
                $profile = $query->orderByDesc('is_default')->orderByDesc('updated_at')->first();
                if ($profile) {
                    $profiles->push($profile);
                }
            } else {
                $profiles = $query->get();
            }

            if ($profiles->isEmpty()) {
                $this->error("No voice profiles found for user: {$user->email}");
                return self::FAILURE;
            }
        } else {
            $this->error('You must provide either --user or --profile option.');
            return self::FAILURE;
        }

        $this->info("Found {$profiles->count()} voice profile(s) to rebuild.");

        foreach ($profiles as $profile) {
            $this->rebuildProfile($profile, $filters, $sync);
        }

        return self::SUCCESS;
    }

    private function buildFilters(): array
    {
        $filters = [];

        if ($this->option('source-id')) {
            $filters['source_id'] = (int) $this->option('source-id');
        }
        if ($this->option('min-engagement')) {
            $filters['min_engagement'] = (float) $this->option('min-engagement');
        }
        if ($this->option('start-date')) {
            $filters['start_date'] = $this->option('start-date');
        }
        if ($this->option('end-date')) {
            $filters['end_date'] = $this->option('end-date');
        }
        if ($this->option('exclude-replies')) {
            $filters['exclude_replies'] = true;
        }
        if ($this->option('limit')) {
            $filters['limit'] = (int) $this->option('limit');
        }
        if ($this->option('schema-version')) {
            $filters['schema_version'] = $this->option('schema-version');
        }

        return $filters;
    }

    private function rebuildProfile(VoiceProfile $profile, array $filters, bool $sync): void
    {
        $this->line('');
        $this->info("Rebuilding voice profile: {$profile->id}");
        $this->line("  Name: " . ($profile->name ?? '(unnamed)'));
        $this->line("  Current status: {$profile->status}");
        $this->line("  Current sample_size: {$profile->sample_size}");

        if ($sync) {
            $this->line('  Mode: Synchronous');

            // Set status to processing
            $profile->status = 'processing';
            $profile->updated_at = now();
            $profile->save();

            try {
                /** @var VoiceProfileBuilderService $builder */
                $builder = app(VoiceProfileBuilderService::class);
                $profile = $builder->rebuild($profile, $filters);

                $profile->status = 'ready';
                $profile->updated_at = now();
                $profile->save();

                $this->info("  ✓ Rebuilt successfully!");
                $this->line("    Confidence: {$profile->confidence}");
                $this->line("    Sample size: {$profile->sample_size}");
                $this->line("    Traits preview: " . ($profile->traits_preview ?? '(none)'));
            } catch (\Throwable $e) {
                $profile->status = 'error';
                $profile->updated_at = now();
                $profile->save();

                $this->error("  ✗ Rebuild failed: {$e->getMessage()}");
            }
        } else {
            $this->line('  Mode: Queued (async)');

            // Set status to queued
            $profile->status = 'queued';
            $profile->updated_at = now();
            $profile->save();

            // Dispatch job
            RebuildVoiceProfileJob::dispatch($profile->id, $filters);

            $this->info("  ✓ Job dispatched to queue");
            $this->line("    Run 'php artisan queue:work' to process the job");
        }
    }
}
