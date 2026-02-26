<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Models\VoiceProfilePost;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaundryOS\SocialWatcher\Models\NormalizedContent;

class VoiceAttachPosts extends Command
{
    protected $signature = 'voice:posts:attach '
        . '{--organization= : Organization id or slug}'
        . ' {--user= : User id or email}'
        . ' {--create : Create a voice profile if none exists}'
        . ' {--name= : Name for the voice profile (used on create or set if empty)}'
        . ' {--profile= : Existing voice profile id to use}'
        . ' {--posts= : Comma-separated NormalizedContent IDs}'
        . ' {--platform= : Platform filter when auto-selecting posts (e.g., twitter|instagram|linkedin|youtube)}'
        . ' {--username= : Author username filter when auto-selecting posts}'
        . ' {--limit=50 : Max posts to auto-select when using platform/username}'
        . ' {--source-id= : Social Watcher source_id to auto-select posts from}'
        . ' {--rebuild : Rebuild the voice profile after attaching posts}';

    protected $aliases = [
        'voice:attach-posts',
    ];

    protected $description = 'Attach Social Watcher normalized content posts to a Voice Profile, optionally creating the profile.';

    public function handle(): int
    {
        $orgInput = trim((string) $this->option('organization'));
        $userInput = trim((string) $this->option('user'));
        $create = (bool) $this->option('create');
        $name = $this->option('name') ? trim((string) $this->option('name')) : null;
        $profileId = $this->option('profile') ? trim((string) $this->option('profile')) : null;
        $postsOpt = $this->option('posts') ? trim((string) $this->option('posts')) : '';
        $platform = $this->option('platform') ? strtolower(trim((string) $this->option('platform'))) : '';
        $username = $this->option('username') ? trim((string) $this->option('username')) : '';
        $limit = (int) $this->option('limit');
        $sourceId = $this->option('source-id');
        $rebuild = (bool) $this->option('rebuild');

        $org = null;
        $user = null;
        
        // If a profile id is provided, allow running without --organization/--user
        if ($profileId) {
            $profile = VoiceProfile::query()->where('id', $profileId)->first();
            if (!$profile) {
                $this->error('Voice profile not found: ' . $profileId);
                return self::FAILURE;
            }
            // If organization was provided, verify it matches the profile's org
            if ($orgInput !== '') {
                $orgCheck = Organization::query()->where('id', $profile->organization_id)->first();
                if (!$orgCheck) {
                    $this->error('Organization not found for profile: ' . $profile->organization_id);
                    return self::FAILURE;
                }
                // If orgInput is a slug or id that doesn't match, fail
                $matches = $orgInput === $orgCheck->id || $orgInput === $orgCheck->slug;
                if (!$matches) {
                    $this->error('Provided --organization does not match profile organization.');
                    return self::FAILURE;
                }
                $org = $orgCheck;
            }
        } else {
            // No profile id: require both org and user and resolve them
            if ($orgInput === '' || $userInput === '') {
                $this->error('Both --organization and --user are required when --profile is not provided.');
                return self::FAILURE;
            }

            // Resolve organization by id or slug
            $orgQ = Organization::query();
            if (Str::isUuid($orgInput)) {
                $orgQ->where('id', $orgInput);
            } else {
                $orgQ->where('slug', $orgInput);
            }
            $org = $orgQ->first();
            if (!$org) {
                $this->error('Organization not found by id or slug: ' . $orgInput);
                return self::FAILURE;
            }

            // Resolve user by id or email
            $userQ = User::query();
            if (Str::isUuid($userInput)) {
                $userQ->where('id', $userInput);
            } else {
                $userQ->where('email', $userInput);
            }
            $user = $userQ->first();
            if (!$user) {
                $this->error('User not found by id or email: ' . $userInput);
                return self::FAILURE;
            }
        }

        // Resolve or create profile
        $profile = $profileId ? ($profile ?? null) : null;
        if ($profileId) {
            // $profile already resolved above
        } else {
            $profile = VoiceProfile::query()
                ->where('organization_id', $org->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$profile) {
                if (!$create) {
                    $this->error('No profile found for this org+user. Pass --create to create one, or provide --profile.');
                    return self::FAILURE;
                }
                $profile = new VoiceProfile();
                $profile->organization_id = $org->id;
                $profile->user_id = $user->id;
                $profile->name = $name ?: null;
                $profile->traits = null;
                $profile->confidence = 0.0;
                $profile->sample_size = 0;
                $profile->updated_at = now();
                $profile->save();
                $this->info('Created voice profile: ' . $profile->id);
            } elseif ($name && empty($profile->name)) {
                $profile->name = $name;
                $profile->save();
                $this->info('Set profile name: ' . $profile->name);
            }
        }

        // Parse posts
        $ids = array_values(array_filter(array_map(function ($s) {
            $s = trim((string) $s);
            return $s !== '' ? $s : null;
        }, explode(',', $postsOpt))));

        // If not provided explicitly, auto-select by source-id, else platform+username
        if (empty($ids) && $sourceId !== null && $sourceId !== '') {
            $q = NormalizedContent::query()->where('source_id', (int) $sourceId);
            $q->orderByDesc('engagement_score')->orderByDesc('published_at');
            $rows = $q->limit(max(1, min($limit, 500)))->get(['id']);
            $ids = $rows->pluck('id')->all();
            $this->info('Auto-selected posts by source_id: ' . count($ids));
        }

        if (empty($ids) && ($platform !== '' || $username !== '')) {
            $q = NormalizedContent::query();
            if ($platform !== '') {
                $plat = $platform;
                $alts = $plat === 'twitter' ? ['twitter','x'] : ($plat === 'x' ? ['x','twitter'] : [$plat]);
                $q->whereIn('platform', $alts);
            }
            if ($username !== '') {
                $q->whereRaw('LOWER(author_username) = ?', [strtolower($username)]);
            }
            $q->orderByDesc('engagement_score')->orderByDesc('published_at');
            $rows = $q->limit(max(1, min($limit, 500)))->get(['id']);
            $ids = $rows->pluck('id')->all();
            $this->info('Auto-selected posts: ' . count($ids));
        }

        if (empty($ids)) {
            $this->warn('No posts provided via --posts. Skipping attach.');
            $this->line('Profile id: ' . $profile->id);
        } else {
            $found = NormalizedContent::query()->whereIn('id', $ids)->get();
            $foundMap = $found->keyBy('id');
            $attached = 0;
            $missing = [];

            foreach ($ids as $id) {
                $row = $foundMap->get($id);
                if (!$row) {
                    $missing[] = $id;
                    continue;
                }
                VoiceProfilePost::query()->updateOrCreate(
                    [
                        'voice_profile_id' => $profile->id,
                        'normalized_content_id' => $id,
                    ],
                    [
                        'source_type' => $row->platform ?? null,
                        'weight' => null,
                        'locked' => false,
                    ]
                );
                $attached++;
            }

            $this->info("Profile: {$profile->id}");
            $this->info("Attached: {$attached}");
            if (!empty($missing)) {
                $this->warn('Missing (not found in sw_normalized_content):');
                foreach ($missing as $m) {
                    $this->line(' - ' . $m);
                }
            }
        }

        // Optional rebuild
        if ($rebuild) {
            try {
                /** @var \App\Services\Voice\VoiceProfileBuilderService $builder */
                $builder = app(\App\Services\Voice\VoiceProfileBuilderService::class);
                $profile = $builder->rebuild($profile, []);
                $this->info('Rebuilt profile. Confidence: ' . $profile->confidence . ' Sample size: ' . $profile->sample_size);
            } catch (\Throwable $e) {
                $this->error('Rebuild failed: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
