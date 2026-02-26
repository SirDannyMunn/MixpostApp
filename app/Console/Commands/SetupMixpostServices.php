<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Inovector\Mixpost\Models\Service;

class SetupMixpostServices extends Command
{
    protected $signature = 'mixpost:setup-services 
                            {--force : Force recreate services even if they exist}';

    protected $description = 'Setup Mixpost social media services from .env credentials';

    public function handle()
    {
        $this->info('Setting up Mixpost services...');
        $this->newLine();

        $servicesConfigured = 0;

        // Twitter/X Setup
        if ($this->setupTwitter()) {
            $servicesConfigured++;
        }

        // Facebook Setup
        if ($this->setupFacebook()) {
            $servicesConfigured++;
        }

        // LinkedIn Setup
        if ($this->setupLinkedIn()) {
            $servicesConfigured++;
        }

        // TikTok Setup
        if ($this->setupTikTok()) {
            $servicesConfigured++;
        }

        // Instagram Setup
        if ($this->setupInstagram()) {
            $servicesConfigured++;
        }

        // Pinterest Setup
        if ($this->setupPinterest()) {
            $servicesConfigured++;
        }

        // YouTube Setup
        if ($this->setupYouTube()) {
            $servicesConfigured++;
        }

        $this->newLine();
        $this->info("✓ Setup complete! Configured {$servicesConfigured} service(s).");
        
        // Clear cache to reload services
        $this->call('config:clear');
        $this->call('cache:clear');

        return Command::SUCCESS;
    }

    protected function setupTwitter(): bool
    {
        $clientId = env('MIXPOST_TWITTER_CLIENT_ID');
        $clientSecret = env('MIXPOST_TWITTER_CLIENT_SECRET');
        $tier = env('MIXPOST_TWITTER_TIER', 'basic'); // free, basic, legacy

        if (!$clientId || !$clientSecret) {
            $this->warn('⚠ Twitter: Skipping - Missing MIXPOST_TWITTER_CLIENT_ID or MIXPOST_TWITTER_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'twitter')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ Twitter: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ Twitter: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'twitter';
        $service->configuration = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'tier' => $tier
        ];
        $service->active = true;
        $service->save();

        $this->info("✓ Twitter: Configured successfully (tier: {$tier})");
        return true;
    }

    protected function setupFacebook(): bool
    {
        $clientId = env('MIXPOST_FACEBOOK_CLIENT_ID');
        $clientSecret = env('MIXPOST_FACEBOOK_CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            $this->warn('⚠ Facebook: Skipping - Missing MIXPOST_FACEBOOK_CLIENT_ID or MIXPOST_FACEBOOK_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'facebook')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ Facebook: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ Facebook: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'facebook';
        $service->configuration = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];
        $service->active = true;
        $service->save();

        $this->info('✓ Facebook: Configured successfully');
        return true;
    }

    protected function setupLinkedIn(): bool
    {
        $clientId = env('MIXPOST_LINKEDIN_CLIENT_ID');
        $clientSecret = env('MIXPOST_LINKEDIN_CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            $this->warn('⚠ LinkedIn: Skipping - Missing MIXPOST_LINKEDIN_CLIENT_ID or MIXPOST_LINKEDIN_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'linkedin')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ LinkedIn: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ LinkedIn: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'linkedin';
        $service->configuration = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];
        $service->active = true;
        $service->save();

        $this->info('✓ LinkedIn: Configured successfully');
        return true;
    }

    protected function setupTikTok(): bool
    {
        $clientKey = env('MIXPOST_TIKTOK_CLIENT_KEY');
        $clientSecret = env('MIXPOST_TIKTOK_CLIENT_SECRET');

        if (!$clientKey || !$clientSecret) {
            $this->warn('⚠ TikTok: Skipping - Missing MIXPOST_TIKTOK_CLIENT_KEY or MIXPOST_TIKTOK_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'tiktok')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ TikTok: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ TikTok: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'tiktok';
        $service->configuration = [
            'client_key' => $clientKey,
            'client_secret' => $clientSecret
        ];
        $service->active = true;
        $service->save();

        $this->info('✓ TikTok: Configured successfully');
        return true;
    }

    protected function setupInstagram(): bool
    {
        $clientId = env('MIXPOST_INSTAGRAM_CLIENT_ID');
        $clientSecret = env('MIXPOST_INSTAGRAM_CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            $this->warn('⚠ Instagram: Skipping - Missing MIXPOST_INSTAGRAM_CLIENT_ID or MIXPOST_INSTAGRAM_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'instagram')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ Instagram: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ Instagram: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'instagram';
        $service->configuration = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];
        $service->active = true;
        $service->save();

        $this->info('✓ Instagram: Configured successfully');
        return true;
    }

    protected function setupPinterest(): bool
    {
        $clientId = env('MIXPOST_PINTEREST_CLIENT_ID');
        $clientSecret = env('MIXPOST_PINTEREST_CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            $this->warn('⚠ Pinterest: Skipping - Missing MIXPOST_PINTEREST_CLIENT_ID or MIXPOST_PINTEREST_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'pinterest')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ Pinterest: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ Pinterest: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'pinterest';
        $service->configuration = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];
        $service->active = true;
        $service->save();

        $this->info('✓ Pinterest: Configured successfully');
        return true;
    }

    protected function setupYouTube(): bool
    {
        $clientId = env('MIXPOST_YOUTUBE_CLIENT_ID');
        $clientSecret = env('MIXPOST_YOUTUBE_CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            $this->warn('⚠ YouTube: Skipping - Missing MIXPOST_YOUTUBE_CLIENT_ID or MIXPOST_YOUTUBE_CLIENT_SECRET');
            return false;
        }

        $existing = Service::where('name', 'youtube')->first();

        if ($existing && !$this->option('force')) {
            $this->line('→ YouTube: Already configured (use --force to recreate)');
            return false;
        }

        if ($existing && $this->option('force')) {
            $this->warn('→ YouTube: Deleting existing configuration...');
            $existing->delete();
        }

        $service = new Service();
        $service->id = Str::uuid();
        $service->name = 'youtube';
        $service->configuration = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];
        $service->active = true;
        $service->save();

        $this->info('✓ YouTube: Configured successfully');
        return true;
    }
}
