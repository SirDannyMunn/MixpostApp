# Intent Classification (Read/Write)

This document describes how the current intent classification system works for AI Canvas chat and document updates.

## Purpose

Intent classification determines whether a user request:
- needs to read the current document (`read`)
- should modify the document (`write`)

The front end uses this to route requests to "chat only" vs "document update" behavior. The backend returns canonical booleans and the UI decides whether a write command should be allowed.

## API entry point

Endpoint:
- `POST /api/v1/ai/classify-intent`

Handler:
- `App\Http\Controllers\Api\V1\AiController::classifyIntent`

## Request shape

```json
{
  "message": "string",
  "document_context": "string | {\"document\":\"string\"}",
  "options": {
    "mode": "generate | research"
  }
}
```

Notes:
- `document_context` can be a plain string or an object with `{ document }`.
- `options.mode` is optional. When it is `"research"`, the API forces `write = false` regardless of classifier output.

## Response shape

```json
{"read":false,"write":true}
```

Fields:
- `read`: whether the assistant needs the current document to answer the request.
- `write`: whether the assistant should modify the document.

## Classification flow

1) `AiController::classifyIntent` validates inputs and normalizes `document_context`.
2) It builds a prompt with `buildClassificationPrompt(message, documentContext)`.
3) It calls `OpenRouterService::classify` using the classifier model.
4) It returns `{read, write}` booleans (defaulting to `false` when absent).

Key files:
- `app/Http/Controllers/Api/V1/AiController.php`
- `app/Services/OpenRouterService.php`

## Prompt behavior

The classifier prompt:
- asks two questions: "read?" and "write?"
- includes a 200 character preview of the current document (if any)
- includes examples for read-only chat, read+write edits, and creation requests

Research mode rules are included in the prompt:
- research, analysis, trend discovery, and angle/hook ideation are treated as read-only (`write = false`)
- `read` depends on whether the request references the current document

## Research mode override

If the caller sets `options.mode = "research"`, the API forces `write = false` even if the classifier returns `write = true`. This prevents document mutation during research flows while still allowing read access.

## Model and logging

Model selection:
- The classifier uses `services.openrouter.classifier_model` (or `OPENROUTER_CLASSIFIER_MODEL`).

Logging:
- `ai.classify-intent` includes `message_preview`, `has_context`, `forced_research`, and the raw classifier result.
- OpenRouter responses are logged under `openrouter.classify.*`.

## Failure and fallback behavior

If the classifier fails or returns invalid JSON:
- `OpenRouterService::classify` falls back to `{ "intent": "chat_only", "confidence": 1.0 }`
- `AiController::classifyIntent` defaults `read = false` and `write = false`

This makes the system safe by default: no document updates occur unless explicitly classified as a write.
