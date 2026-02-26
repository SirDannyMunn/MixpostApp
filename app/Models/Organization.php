<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Organization extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name', 'slug', 'logo_url', 'settings',
        'subscription_tier', 'subscription_status',
        'trial_ends_at', 'subscription_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'settings' => 'array',
    ];

    public function members()
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot('role', 'invited_at', 'joined_at', 'invited_by')
            ->withTimestamps();
    }

    public function memberships()
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function folders() { return $this->hasMany(Folder::class); }
    public function tags() { return $this->hasMany(Tag::class); }
    public function bookmarks() { return $this->hasMany(Bookmark::class); }
    public function templates() { return $this->hasMany(Template::class); }
    public function mediaPacks() { return $this->hasMany(MediaPack::class); }
    public function mediaImages() { return $this->hasMany(MediaImage::class); }
    public function projects() { return $this->hasMany(Project::class); }
    public function socialAccounts() { return $this->hasMany(SocialAccount::class); }
    public function scheduledPosts() { return $this->hasMany(ScheduledPost::class); }

    public static function defaultSettings(): array
    {
        return [
            'core_business_context' => [
                'business_description' => '',
                'industry' => '',
                'primary_audience' => [
                    'role' => '',
                    'industry' => '',
                    'sophistication_level' => 'intermediate',
                ],
                'pricing_positioning' => 'mid-market',
                'sales_motion' => 'self-serve',
            ],
            'positioning_differentiation' => [
                'primary_value_proposition' => '',
                'top_differentiators' => [],
                'main_competitors' => [],
                'why_we_win' => '',
                'what_we_do_not_compete_on' => '',
            ],
            'audience_psychology' => [
                'core_pain_points' => [],
                'desired_outcomes' => [],
                'common_objections' => [],
                'skepticism_triggers' => [],
                'buying_emotions' => [],
                'language_they_use' => [],
            ],
            'brand_voice_tone' => [
                'brand_personality_traits' => [],
                'tone_formal_casual' => 5,
                'tone_bold_safe' => 5,
                'tone_playful_serious' => 5,
                'things_we_never_say' => [],
                'allowed_language' => [
                    'emojis' => true,
                    'slang' => false,
                    'swearing' => false,
                    'metaphors' => true,
                ],
            ],
            'visual_direction' => [
                'visual_style' => [],
                'brand_colors' => [
                    'primary' => '#d9ff00',
                    'secondary' => '#ec4899',
                    'accent' => '#06b6d4',
                ],
                'font_preferences' => [
                    'heading' => 'Inter',
                    'body' => 'Inter',
                ],
                'logo_usage' => [],
            ],
            'constraints_rules' => [
                'hard_constraints' => [],
                'soft_guidelines' => [],
                'content_disallowed' => [],
                'content_must_include' => [],
            ],
            'advanced_settings' => [
                'examples_of_good_content' => [],
                'examples_of_bad_content' => [],
                'seo_keywords' => [],
                'localization' => [
                    'primary_locale' => 'en_US',
                    'time_zone' => 'UTC',
                    'date_format' => 'Y-m-d',
                ],
            ],
        ];
    }

    public function getSettingsWithDefaultsAttribute(): array
    {
        $settings = $this->settings ?? [];
        return array_replace_recursive(self::defaultSettings(), $settings);
    }
}
