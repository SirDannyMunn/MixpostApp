# Image Generation Service: Architecture and Flow

Files:
- `app/Services/AIImageGenerationService.php`
- `app/Services/ImageGeneration/ImageGeneratorRouter.php`
- `app/Services/ImageGeneration/ImageProviderInterface.php`
- `app/Services/ImageGeneration/Providers/GoogleImagesProvider.php`
- `app/Services/ImageGeneration/Providers/OpenRouterImagesProvider.php`

This document describes the image generation flow, provider routing, storage behavior, and configuration used by the image generation service.

## High-Level Flow

1. `AIImageGenerationService::generateAndSaveImage(...)` receives a prompt and options.
2. `ImageGeneratorRouter::generate(...)` selects a provider (Google or OpenRouter) based on the model string and availability.
3. Provider returns base64 image data (or a URL).
4. The service decodes bytes, computes dimensions, stores the image and thumbnail, and creates a `MediaImage` record.
5. If provider calls fail and fallback is enabled, a local placeholder image is generated instead.

## Public API

### generateAndSaveImage(Organization $organization, int $uploadedByUserId, string $prompt, string $aspectRatio = '1:1', ?string $filename = null, ?int $packId = null, ?string $model = null): MediaImage

Primary entry point to generate an image and persist it.

Inputs:
- `organization`: used for storage paths and `MediaImage` association.
- `uploadedByUserId`: stored on `MediaImage`.
- `prompt`: required text prompt for the provider.
- `aspectRatio`: optional, default `1:1`. Used for OpenRouter size mapping and local placeholder size.
- `filename`: optional. If omitted, a slugged name is created from the prompt.
- `packId`: optional. Stored on `MediaImage`.
- `model`: optional. Controls routing and provider model selection.

Outputs:
- A `MediaImage` record with fields including:
  - `file_path`, `thumbnail_path`, `file_size`, `mime_type`, `width`, `height`.
  - `generation_type = 'ai_generated'`, `ai_prompt = <prompt>`.

## Routing and Providers

`ImageGeneratorRouter::generate(...)` routes based on the `model` string:

- `google` or empty model: calls Google first, then OpenRouter if configured.
- `openrouter` or `openai` prefix: calls OpenRouter directly.
- Any other model: Google first, then OpenRouter fallback if configured.

OpenRouter fallback is only attempted when `services.openrouter.api_key` is configured.

### Provider Interface

`ImageProviderInterface` expects:
- `prompt` (required)
- `aspect_ratio` (optional)
- `model` (optional)

Returns an array with at least one of:
- `b64` (base64 data)
- `url` (remote image URL)
Plus metadata such as `model`.

### GoogleImagesProvider

Generates images via Gemini 3 image-capable models.

Config:
- `services.google.api_key` (required)
- `services.google.api_url` (default: `https://generativelanguage.googleapis.com/v1beta`)
- `services.google.image_model` (default: `gemini-3-pro-image-preview`)

Behavior:
- Sends `generateContent` with `responseModalities = ['IMAGE', 'TEXT']`.
- Extracts base64 from `inlineData`.
- Logs detailed error context on transport or API failure.

### OpenRouterImagesProvider

Generates images via OpenRouter-compatible images API.

Config:
- `services.openrouter.api_key` (required)
- `services.openrouter.api_url` (default: `https://api.openrouter.ai/v1`)
- `services.openrouter.default_model` (default: `openai/dall-e-3`)

Behavior:
- Sends `/images/generations` with `response_format = b64_json`.
- Converts `aspect_ratio` to size:
  - `1:1` -> `1024x1024`
  - `16:9` -> `1792x1024`
  - `9:16` -> `1024x1792`
  - `4:3` -> `1024x768`
- Extracts base64 from `data[0].b64_json` or compatible fields.

## Storage and Persistence

- Images are stored on the default filesystem disk (`filesystems.default`).
- Final path: `media/{organization_id}/images/{filename}`.
- Thumbnails are generated at `400x400` and stored at:
  - `media/{organization_id}/images/thumbnails/{filename}`.
- File extension is normalized to `.png` if no supported extension is provided.
- A unique filename is always generated (`{base}_{timestamp}_{random}.{ext}`).

## Fallback Placeholder

If provider calls fail and fallback is enabled, a placeholder image is generated locally.

Config:
- `services.openrouter.fallback_placeholder` (default true)

Behavior:
- Creates a solid canvas with a subtle border.
- Uses the aspect ratio mapping defined in `AIImageGenerationService::generatePlaceholder`.
- Records the `model` as `placeholder` in the return metadata.

## Error Handling and Logging

- Providers throw exceptions on missing API keys, transport errors, or API failures.
- Each provider logs:
  - Endpoint URL and status.
  - Truncated payload and prompt preview.
  - Response body excerpt on errors.
- `AIImageGenerationService` either rethrows exceptions or falls back to a placeholder based on config.

## Extensibility Notes

- Add new providers by implementing `ImageProviderInterface` and extending `ImageGeneratorRouter`.
- If a provider returns URLs instead of base64, `AIImageGenerationService` downloads the bytes before storing.
