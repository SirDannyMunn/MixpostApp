**Organization Settings**

- Purpose: store organization-scoped business context, brand voice, visual direction, and constraints used by AI and content workflows.
- Storage: `organizations.settings` (jsonb), merged with defaults at read time.
- Migration: `database/migrations/2025_12_15_000000_add_settings_to_organizations_table.php`.

**How It Works**

- Organization context is resolved by `App\Http\Middleware\EnsureOrganizationContext` via `X-Organization-Id` header, `organization_id` query param, or a single-membership fallback.
- `GET /api/v1/organization-settings` returns `settings_with_defaults` (defaults merged with stored settings).
- `PUT /api/v1/organization-settings` validates partial updates, merges with existing settings (`array_replace_recursive`), saves, then refreshes `business_profile_snapshot` via `BusinessProfileDistiller::ensureSnapshot`.
- `POST /api/v1/organization-settings/reset` clears `organizations.settings` (owner-only) and returns defaults.
- `GET /api/v1/organization-settings/export-for-ai` returns an AI-formatted summary plus the full settings payload.

**Access Control**

- Middleware: `auth:sanctum`, `organization`, `billing.access` (org-scoped group in `routes/api.php`).
- Update requires `OrganizationPolicy@update`.
- Reset requires role `owner` (checked directly in the controller).

**Settings Schema (Defaults)**

```json
{
  "core_business_context": {
    "business_description": "",
    "industry": "",
    "primary_audience": {
      "role": "",
      "industry": "",
      "sophistication_level": "intermediate"
    },
    "pricing_positioning": "mid-market",
    "sales_motion": "self-serve"
  },
  "positioning_differentiation": {
    "primary_value_proposition": "",
    "top_differentiators": [],
    "main_competitors": [],
    "why_we_win": "",
    "what_we_do_not_compete_on": ""
  },
  "audience_psychology": {
    "core_pain_points": [],
    "desired_outcomes": [],
    "common_objections": [],
    "skepticism_triggers": [],
    "buying_emotions": [],
    "language_they_use": []
  },
  "brand_voice_tone": {
    "brand_personality_traits": [],
    "tone_formal_casual": 5,
    "tone_bold_safe": 5,
    "tone_playful_serious": 5,
    "things_we_never_say": [],
    "allowed_language": {
      "emojis": true,
      "slang": false,
      "swearing": false,
      "metaphors": true
    }
  },
  "visual_direction": {
    "visual_style": [],
    "brand_colors": {
      "primary": "#d9ff00",
      "secondary": "#ec4899",
      "accent": "#06b6d4"
    },
    "font_preferences": {
      "heading": "Inter",
      "body": "Inter"
    },
    "logo_usage": []
  },
  "constraints_rules": {
    "hard_constraints": [],
    "soft_guidelines": [],
    "content_disallowed": [],
    "content_must_include": []
  },
  "advanced_settings": {
    "examples_of_good_content": [],
    "examples_of_bad_content": [],
    "seo_keywords": [],
    "localization": {
      "primary_locale": "en_US",
      "time_zone": "UTC",
      "date_format": "Y-m-d"
    }
  }
}
```

**Validation Notes**

- `core_business_context.primary_audience.sophistication_level`: `beginner|intermediate|advanced`.
- `brand_voice_tone.tone_*` fields: integer 1-10.
- `advanced_settings.localization.*`: strings.
- All sections accept partial updates; arrays are merged recursively with existing values.

**Derived Data**

- `business_profile_snapshot` is stored inside `organizations.settings` after updates.
- It includes `summary`, `audience_summary`, `offer_summary`, `tone_signature`, and other AI-ready fields.
- Regeneration is handled by `App\Services\Ai\BusinessProfileDistiller`.

**Export For AI**

- Response includes:
  - `organization`: `{ id, name, slug }`
  - `export`: newline-delimited summary
  - `settings`: full `settings_with_defaults` payload
- The export lines are assembled from core business context, positioning, audience psychology, brand voice tone, constraints, and SEO keywords.

**Models**

- `App\Models\Organization`: owns `settings` and the `settings_with_defaults` accessor.
- `App\Models\OrganizationMember`: drives membership and roles used by policies and middleware.
- `App\Models\User`: membership checks (`isMemberOf`, `roleIn`) are used to authorize access and updates.
