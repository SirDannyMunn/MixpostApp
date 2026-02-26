# AI Controller: generateChatResponse

This document explains the end‑to‑end behavior of `App\Http\Controllers\Api\V1\AiController::generateChatResponse`, including all inputs, internal variables, control flow, and its interaction with the content generation pipeline.

File: `app/Http/Controllers/Api/V1/AiController.php`
Method: `public function generateChatResponse(Request $request, OpenRouterService $openRouter, Retriever $retriever, ContentGeneratorService $generator): JsonResponse`

## Purpose

- Provide a single chat endpoint that can either:
  - Read/consider an optional document and references supplied by the client, and
  - Generate or edit content while enforcing constraints and VIP overrides via `ContentGeneratorService`.
- Return a structured response where edits come back as a JSON command (replace_document), enabling the client to apply changes deterministically to the editor/document.

## Inputs (validated request)

- `message` (string, required): User’s chat message or instruction.
- `conversation_history` (array, optional): Prior chat turns with roles `user|assistant|system`. Content may be empty (tolerates previous failures).
- `document_context` (string or object, optional):
  - If string: full document text.
  - If object: `{ document?: string, references?: array }`
    - `references[]` items can include:
      - `type` (string, required if present): `bookmark|file|snippet|url|template|swipe|fact|voice`
      - `id` (string, optional)
      - `name|title` (string, optional)
      - `content` (string, optional)
      - `url` (url, optional)
- `options` (array, optional): generation constraints and policies passed through to `ContentGeneratorService`. Supported keys include:
  - `max_chars` (int)
  - `emoji` (`allow|disallow`)
  - `tone` (string)
  - `intent` (`educational|persuasive|emotional|story|contrarian`)
  - `funnel_stage` (`tof|mof|bof`)
  - `voice_profile_id` (string)
  - `voice_inline` (string)
  - `use_retrieval` (bool)
  - `retrieval_limit` (int)
  - `use_business_facts` (bool)
  - `swipe_mode` (`auto|none|strict`)
  - `swipe_ids` (array of strings)
  - `template_id` (string)
  - `context_token_budget` (int)
  - `business_context` (string)
- `platform` (string, optional): social/content platform, defaults to `generic`.

## High‑Level Flow

1. Validate input and normalize document context into:
   - `docText`: current document string or null
   - `docEmbeddedReferences`: typed references array
2. Resolve references into two artifacts:
   - `resolvedReferences`: compact, human‑readable reference chunks (label + content) for prompt context
   - `overrides` (VIP): structured override directives for the generator
     - `template_id`, `swipes[]`, `facts[]`, `knowledge[]`
     - Voice: `voice_profile_id` or `voice_inline` mapped from `voice` reference
3. Build `user_context` string for the generator from `docText` and `resolvedReferences`.
4. Set chat‑friendly defaults (`retrieval_limit` = 3) and attach overrides/voice overrides into `options`.
5. Call `ContentGeneratorService::generate(...)` with `orgId`, `userId`, `message`, `platform`, and `options`.
6. Return a JSON response with a `replace_document` command to deterministically update the client document.

## Variable Reference

- `message` (string): The user’s instruction or question; passed to generator as the prompt.
- `conversationHistory` (array): Prior turns. Currently used for logging; generation is driven by `message` + `user_context`.
- `documentContext` (mixed): Raw input that is normalized into `docText` and `docEmbeddedReferences`.
- `docText` (string|null): Current document content used as part of `user_context`.
- `docEmbeddedReferences` (array): Array of `{type,id,name/title,content,url}` providing inline references.
- `resolvedReferences` (array): Flattened references for readable context (label+content), e.g. rendered under “REFERENCES:” in `user_context`.
- `overrides` (array): VIP override envelope passed into generator options:
  - `template_id` (string|null): Force a template.
  - `swipes` (array<string>): Swipe IDs to bias structure.
  - `facts` (array<string>): Fact IDs to include.
  - `knowledge` (array<object>): Inline knowledge items `{ id?, type, title?, content }`.
- `voiceProfileIdOpt` (string|null): From a `voice` reference with an ID; sets `options.voice_profile_id`.
- `voiceInlineOpt` (string|null): From a `voice` reference with inline content; sets `options.voice_inline`.
- `options` (array): Merged generation options; also set:
  - `retrieval_limit` forced to `3` for responsiveness.
  - `user_context` extended with document and references.
  - `overrides` added when present.
- `platform` (string): Target platform; forwarded to the generator.

## Reference Resolution Rules

For each `document_context.references[]` item:

- `template`: sets `overrides.template_id` if `id` present.
- `swipe`: appends `id` to `overrides.swipes`.
- `fact`: appends `id` to `overrides.facts`.
- `voice`: if `id` present, sets `voiceProfileIdOpt`; else if `content` provided, sets `voiceInlineOpt` (trimmed to 2000 chars).
- `bookmark|file|snippet|url` (and default): if `content` exists, append an object to `overrides.knowledge[]` with `{ id, type, title, content }`.

Additionally, a human‑readable `resolvedReferences[]` is built for inclusion in `user_context`.

## Generator Interaction

The controller delegates creation/editing to `ContentGeneratorService::generate`:

```php
$result = $generator->generate(
    orgId: (string) $organization->id,
    userId: (string) $user->id,
    prompt: $message,
    platform: $platform,
    options: $options,
);
```

Key option pass‑throughs:
- `user_context` includes the current document and readable references block.
- `overrides` contains VIP directives (template/swipes/facts/knowledge).
- `voice_profile_id` or `voice_inline` when resolved from references.
- `retrieval_limit` is trimmed to `3` for chat speed.

The generator returns vetted content; the controller wraps it into a document command:

```json
{
  "response": "Applied your request to the document.",
  "command": { "action": "replace_document", "target": null, "content": "..." }
}
```

If no document context is provided, the message reads:
`Created content using your knowledge and constraints.`

## Logging

- `ai.chat.request`: logs message preview, presence of context, history count, and embedded reference count.

## Error Handling Notes

- Validation enforces types and bounds but tolerates empty assistant messages in history.
- Reference content is trimmed to a reasonable size (e.g., 10KB per chunk, voice inline to 2KB) before passing downstream.
- The generator performs its own validation/repair and fallback regeneration if needed.

## Related Helpers in AiController

- `buildClassificationPrompt(...)`: Builds a system prompt for separate `classifyIntent` endpoint (not used by `generateChatResponse`), returning JSON `{ read: bool, write: bool }`.
- `buildSystemPrompt(...)`: A comprehensive system message builder for document‑aware chat; included in the controller for other flows but not used by `generateChatResponse` since generation is delegated to `ContentGeneratorService`.
- `parseResponse(...)`: Robustly extracts JSON commands from AI output or falls back to natural text.

