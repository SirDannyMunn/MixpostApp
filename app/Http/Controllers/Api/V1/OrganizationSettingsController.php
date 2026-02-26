<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use App\Services\Ai\BusinessProfileDistiller;

class OrganizationSettingsController extends Controller
{
    // GET /api/v1/organization-settings
    public function index(Request $request)
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        if (!$organization) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        return response()->json($organization->settings_with_defaults);
    }

    // PUT /api/v1/organization-settings
    public function update(Request $request)
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        if (!$organization) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $this->authorize('update', $organization);

        $validated = $request->validate([
            'core_business_context' => 'sometimes|array',
            'core_business_context.business_description' => 'sometimes|string|nullable',
            'core_business_context.industry' => 'sometimes|string|nullable',
            'core_business_context.primary_audience' => 'sometimes|array',
            'core_business_context.primary_audience.role' => 'sometimes|string|nullable',
            'core_business_context.primary_audience.industry' => 'sometimes|string|nullable',
            'core_business_context.primary_audience.sophistication_level' => 'sometimes|string|in:beginner,intermediate,advanced',
            'core_business_context.pricing_positioning' => 'sometimes|string|nullable',
            'core_business_context.sales_motion' => 'sometimes|string|nullable',

            'positioning_differentiation' => 'sometimes|array',
            'positioning_differentiation.primary_value_proposition' => 'sometimes|string|nullable',
            'positioning_differentiation.top_differentiators' => 'sometimes|array',
            'positioning_differentiation.main_competitors' => 'sometimes|array',
            'positioning_differentiation.why_we_win' => 'sometimes|string|nullable',
            'positioning_differentiation.what_we_do_not_compete_on' => 'sometimes|string|nullable',

            'audience_psychology' => 'sometimes|array',
            'audience_psychology.core_pain_points' => 'sometimes|array',
            'audience_psychology.desired_outcomes' => 'sometimes|array',
            'audience_psychology.common_objections' => 'sometimes|array',
            'audience_psychology.skepticism_triggers' => 'sometimes|array',
            'audience_psychology.buying_emotions' => 'sometimes|array',
            'audience_psychology.language_they_use' => 'sometimes|array',

            'brand_voice_tone' => 'sometimes|array',
            'brand_voice_tone.brand_personality_traits' => 'sometimes|array',
            'brand_voice_tone.tone_formal_casual' => 'sometimes|integer|min:1|max:10',
            'brand_voice_tone.tone_bold_safe' => 'sometimes|integer|min:1|max:10',
            'brand_voice_tone.tone_playful_serious' => 'sometimes|integer|min:1|max:10',
            'brand_voice_tone.things_we_never_say' => 'sometimes|array',
            'brand_voice_tone.allowed_language' => 'sometimes|array',

            'visual_direction' => 'sometimes|array',
            'visual_direction.visual_style' => 'sometimes|array',
            'visual_direction.brand_colors' => 'sometimes|array',
            'visual_direction.font_preferences' => 'sometimes|array',
            'visual_direction.logo_usage' => 'sometimes|array',

            'constraints_rules' => 'sometimes|array',
            'constraints_rules.hard_constraints' => 'sometimes|array',
            'constraints_rules.soft_guidelines' => 'sometimes|array',
            'constraints_rules.content_disallowed' => 'sometimes|array',
            'constraints_rules.content_must_include' => 'sometimes|array',

            'advanced_settings' => 'sometimes|array',
            'advanced_settings.examples_of_good_content' => 'sometimes|array',
            'advanced_settings.examples_of_bad_content' => 'sometimes|array',
            'advanced_settings.seo_keywords' => 'sometimes|array',
            'advanced_settings.localization' => 'sometimes|array',
            'advanced_settings.localization.primary_locale' => 'sometimes|string',
            'advanced_settings.localization.time_zone' => 'sometimes|string',
            'advanced_settings.localization.date_format' => 'sometimes|string',
        ]);

        $current = $organization->settings ?? [];
        $merged = array_replace_recursive($current, $validated);
        $organization->settings = $merged;
        $organization->save();

        // Trigger/refresh Business Profile Snapshot (non-blocking)
        try {
            BusinessProfileDistiller::ensureSnapshot($organization);
        } catch (\Throwable $e) {
            Log::warning('org.settings.snapshot_failed', [
                'controller' => 'OrganizationSettingsController',
                'action' => 'update',
                'org_id' => (string) $organization->id,
                'user_id' => (string) ($request->user()?->id ?? ''),
                'error' => $e->getMessage(),
            ]);
            // failure-safe: never block settings update
        }

        return response()->json($organization->settings_with_defaults);
    }

    // POST /api/v1/organization-settings/reset
    public function reset(Request $request)
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        if (!$organization) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        // Only owners can fully reset settings
        $role = $request->user()->roleIn($organization);
        if ($role !== 'owner') {
            return response()->json(['message' => 'Only the organization owner can reset settings'], 403);
        }

        $organization->settings = null;
        $organization->save();

        return response()->json($organization->settings_with_defaults);
    }

    // GET /api/v1/organization-settings/export-for-ai
    public function exportForAI(Request $request)
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        if (!$organization) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $settings = $organization->settings_with_defaults;

        $lines = [];
        $lines[] = "Organization: {$organization->name}";
        $lines[] = "Industry: " . ($settings['core_business_context']['industry'] ?? '');
        $lines[] = "Description: " . ($settings['core_business_context']['business_description'] ?? '');
        $lines[] = "Primary Audience: " . trim(implode(' / ', array_filter([
            $settings['core_business_context']['primary_audience']['role'] ?? null,
            $settings['core_business_context']['primary_audience']['industry'] ?? null,
            $settings['core_business_context']['primary_audience']['sophistication_level'] ?? null,
        ])));

        if (!empty($settings['positioning_differentiation']['primary_value_proposition'])) {
            $lines[] = "Value Proposition: " . $settings['positioning_differentiation']['primary_value_proposition'];
        }
        if (!empty($settings['positioning_differentiation']['top_differentiators'])) {
            $lines[] = "Differentiators: " . implode('; ', $settings['positioning_differentiation']['top_differentiators']);
        }
        if (!empty($settings['audience_psychology']['core_pain_points'])) {
            $lines[] = "Pain Points: " . implode('; ', $settings['audience_psychology']['core_pain_points']);
        }
        if (!empty($settings['audience_psychology']['desired_outcomes'])) {
            $lines[] = "Desired Outcomes: " . implode('; ', $settings['audience_psychology']['desired_outcomes']);
        }
        if (!empty($settings['brand_voice_tone']['brand_personality_traits'])) {
            $lines[] = "Brand Traits: " . implode(', ', $settings['brand_voice_tone']['brand_personality_traits']);
        }
        $lines[] = "Tone (1-10): formal→casual=" . ($settings['brand_voice_tone']['tone_formal_casual'] ?? 5)
            . ", bold→safe=" . ($settings['brand_voice_tone']['tone_bold_safe'] ?? 5)
            . ", playful→serious=" . ($settings['brand_voice_tone']['tone_playful_serious'] ?? 5);

        if (!empty($settings['constraints_rules']['hard_constraints'])) {
            $lines[] = "Hard Constraints: " . implode('; ', $settings['constraints_rules']['hard_constraints']);
        }
        if (!empty($settings['constraints_rules']['content_disallowed'])) {
            $lines[] = "Do NOT: " . implode('; ', $settings['constraints_rules']['content_disallowed']);
        }
        if (!empty($settings['advanced_settings']['seo_keywords'])) {
            $lines[] = "SEO Keywords: " . implode(', ', $settings['advanced_settings']['seo_keywords']);
        }

        return response()->json([
            'organization' => $organization->only(['id', 'name', 'slug']),
            'export' => implode("\n", $lines),
            'settings' => $settings,
        ]);
    }
}
