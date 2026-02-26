<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OpenAIService;
use App\Services\OpenRouterService;
use App\Services\Ai\Retriever;
use App\Services\Ai\ContentGeneratorService;
use App\Services\Ai\Classification\ResearchStageClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiController extends Controller
{
    public function assistPrompt(Request $request)
    {
        $data = $request->validate([
            'context' => 'required|string|max:500',
            'language' => 'sometimes|string|max:100',
        ]);

        $ai = app(OpenAIService::class);
        $result = $ai->assistPrompt($data);

        return response()->json($result, 200);
    }

    /**
     * Get generation result by id.
     */
    public function getGeneratedPost(Request $request, string $id): JsonResponse
    {
        $organization = $request->attributes->get('organization');
        $gen = \App\Models\GeneratedPost::where('organization_id', $organization->id)->findOrFail($id);
        return response()->json([
            'id' => $gen->id,
            'status' => $gen->status,
            'content' => $gen->content,
            'validation' => $gen->validation,
        ]);
    }

    /**
     * Generate a social post (MVP) with optional bookmark context.
     * Validates inputs and returns strict JSON: { content, status, validation }
     */
    public function generatePost(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'prompt' => 'required|string|max:5000',
            'context' => 'sometimes|nullable|string|max:20000',
            'options' => 'sometimes|array',
            'options.max_chars' => 'sometimes|integer|min:100|max:4000',
            'options.cta' => 'sometimes|string|in:none,implicit,soft,direct',
            'options.emoji' => 'sometimes|string|in:allow,disallow',
            'options.tone' => 'sometimes|string|max:100',
            // New advanced options
            'options.intent' => 'sometimes|string|in:educational,persuasive,emotional,story,contrarian',
            'options.funnel_stage' => 'sometimes|string|in:tof,mof,bof',
            'options.voice_profile_id' => 'sometimes|string',
            'options.voice_inline' => 'sometimes|string|max:2000',
            'options.use_retrieval' => 'sometimes|boolean',
            'options.retrieval_limit' => 'sometimes|integer|min:0|max:20',
            'options.use_business_facts' => 'sometimes|boolean',
            'options.mode' => 'sometimes|string|in:generate,research',
            'options.research_stage' => 'sometimes|string|in:trend_discovery,deep_research,angle_hooks,saturation_opportunity',
            'options.industry' => 'sometimes|string|max:200',
            'options.platforms' => 'sometimes|array|max:10',
            'options.platforms.*' => 'sometimes|string|max:50',
            'options.limit' => 'sometimes|integer|min:1|max:100',
            'options.hooks' => 'sometimes|integer|min:1|max:10',
            'options.swipe_mode' => 'sometimes|string|in:auto,none,strict',
            'options.swipe_ids' => 'sometimes|array|max:10',
            'options.swipe_ids.*' => 'sometimes|string',
            'options.template_id' => 'sometimes|string',
            'options.context_token_budget' => 'sometimes|integer|min:200|max:8000',
            'options.business_context' => 'sometimes|string|max:20000',
            'bookmark_ids' => 'sometimes|array|max:10',
            'bookmark_ids.*' => 'integer|exists:bookmarks,id',
        ]);

        $platform = (string) $data['platform'];
        $prompt = (string) $data['prompt'];
        $userContext = (string) ($data['context'] ?? '');
        $options = (array) ($data['options'] ?? []);

        // Create generation row and dispatch async job
        $maxChars = (int) ($options['max_chars'] ?? 1200);
        $emojiPolicy = (string) ($options['emoji'] ?? 'disallow');

        $gen = \App\Models\GeneratedPost::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'platform' => $platform,
            'request' => [
                'prompt' => $prompt,
                'context' => $userContext,
                'options' => $options,
                // Per spec: do NOT inject raw bookmark text; store only IDs
                'reference_ids' => (array) ($data['bookmark_ids'] ?? []),
            ],
            'status' => 'queued',
        ]);

        dispatch(new \App\Jobs\GeneratePostJob($gen->id));

        return response()->json([
            'generation_id' => $gen->id,
            'status' => 'queued',
            'limits' => [
                'max_chars' => $maxChars,
                'emoji' => $emojiPolicy,
            ],
        ]);
    }

    /**
     * Rewrite a single sentence according to instruction, returning strict JSON.
     */
    public function rewriteSentence(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $data = $request->validate([
            'generated_post_id' => 'sometimes|nullable|string',
            'sentence' => 'required|string|max:2000',
            'instruction' => 'required|string|max:500',
            'rules' => 'sometimes|array',
            'rules.emoji' => 'sometimes|string|in:allow,disallow',
            'rules.max_chars' => 'sometimes|integer|min:5|max:500',
            'rules.tone' => 'sometimes|string|max:100',
        ]);

        $sentence = (string) $data['sentence'];
        $instruction = (string) $data['instruction'];
        $rules = (array) ($data['rules'] ?? []);
        $emojiPolicy = (string) ($rules['emoji'] ?? 'disallow');
        $maxChars = (int) ($rules['max_chars'] ?? 280);
        $tone = (string) ($rules['tone'] ?? 'consistent with original');

        $system = "You are a precise copy editor. Output STRICT JSON only.\n";
        $system .= "Rewrite the provided sentence per instruction, preserve meaning, keep tone: {$tone}.\n";
        $system .= "Constraints: max_chars={$maxChars}; emoji={$emojiPolicy}.\n";
        $system .= "Return JSON: {\n  \"rewritten_sentence\": \"...\"\n}\n";

        $userMsg = "INSTRUCTION: {$instruction}\n\nSENTENCE: {$sentence}";
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMsg],
        ];

        $decoded = $openRouter->chatJSON($messages, [
            'temperature' => 0.4,
            'max_tokens' => 200,
        ]);

        // Validate JSON shape; retry once if invalid
        $schema = app(\App\Services\Ai\SchemaValidator::class);
        if (!$schema->validate('sentence_rewrite', is_array($decoded) ? $decoded : [])) {
            $decoded = $openRouter->chatJSON($messages, [
                'temperature' => 0.3,
                'max_tokens' => 200,
            ]);
        }

        $out = (string) ($decoded['rewritten_sentence'] ?? '');
        $out = trim($out);
        // Minimal guards
        $ok = $out !== '' && mb_strlen($out) <= $maxChars;
        if ($emojiPolicy === 'disallow' && preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}]/u', $out)) {
            $ok = false;
        }

        // Persist rewrite history (optional linkage to generated post)
        \App\Models\SentenceRewrite::create([
            'organization_id' => $request->attributes->get('organization')?->id ?? 0,
            'user_id' => $request->user()->id,
            'generated_post_id' => !empty($data['generated_post_id']) ? (int) $data['generated_post_id'] : null,
            'original_sentence' => $sentence,
            'instruction' => $instruction,
            'rewritten_sentence' => $out,
            'meta' => [ 'emoji' => $emojiPolicy, 'max_chars' => $maxChars, 'tone' => $tone ],
            'created_at' => now(),
        ]);

        return response()->json([
            'rewritten_sentence' => $out,
            'ok' => $ok,
        ]);
    }

    /**
     * Replay a generation snapshot and return a fresh output for A/B debugging.
     */
    public function replaySnapshot(Request $request, ContentGeneratorService $generator): JsonResponse
    {
        $data = $request->validate([
            'options' => 'sometimes|array',
            'options.max_chars' => 'sometimes|integer|min:50|max:4000',
            'options.emoji' => 'sometimes|string|in:allow,disallow',
            'options.tone' => 'sometimes|string|max:100',
            'options.context_token_budget' => 'sometimes|integer|min:200|max:8000',
            'platform' => 'sometimes|string|max:50',
            'user_context' => 'sometimes|nullable|string|max:20000',
            'store_report' => 'sometimes|boolean',
        ]);

        $organization = $request->attributes->get('organization');
        $id = (string) $request->route('id');
        
        // Try to find snapshot by ID first
        $snap = \App\Models\GenerationSnapshot::where('organization_id', $organization->id)
            ->where('id', $id)
            ->first();
        
        // Fallback: if not found, the ID might be a legacy run_id stored in options
        // This handles old messages created before the snapshot_id fix
        if (!$snap) {
            $snap = \App\Models\GenerationSnapshot::where('organization_id', $organization->id)
                ->whereRaw("options->>'run_id' = ?", [$id])
                ->first();
        }
        
        if (!$snap) {
            abort(404, "Snapshot not found: {$id}");
        }

        $result = $generator->replayFromSnapshot($snap, [
            'platform' => $data['platform'] ?? null,
            'options' => (array) ($data['options'] ?? []),
            'user_context' => $data['user_context'] ?? null,
            'store_report' => (bool) ($data['store_report'] ?? true),
        ]);

        return response()->json([
            'snapshot_id' => $result['metadata']['snapshot_id'] ?? $id,
            'metadata' => [
                'model_used' => $result['metadata']['model_used'] ?? null,
                'total_tokens' => $result['metadata']['total_tokens'] ?? null,
                'processing_time_ms' => $result['metadata']['processing_time_ms'] ?? null,
                'intent' => $result['metadata']['intent'] ?? null,
                'platform' => $result['metadata']['platform'] ?? null,
            ],
            'input_snapshot' => $result['input_snapshot'] ?? [],
            'output' => [
                'content' => $result['content'],
                'validation' => $result['validation'] ?? [],
            ],
            'quality_report' => [
                'overall_score' => $result['quality']['overall_score'] ?? null,
                'breakdown' => $result['quality']['scores'] ?? [],
            ],
            'context' => $result['context'] ?? [],
            'debug_links' => $result['debug_links'] ?? [],
            'original_preview' => mb_substr((string) $snap->output_content, 0, 280),
        ]);
    }

    /**
     * Note: Bookmark content resolution happens inside the job/services layer.
     */

    /**
     * Classify user intent for READ/WRITE permissions + Mode/Submode
     * Single authoritative decision point - no overrides allowed.
     * If conversation_id is provided and conversation is in content_planner mode,
     * bypasses LLM classification and returns content_planner mode directly.
     */
    public function classifyIntent(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            // Accept string or object with { document }
            'document_context' => 'nullable',
            'document_context.document' => 'sometimes|string|max:50000',
            'conversation_id' => 'sometimes|string|uuid',
        ]);

        $message = (string) $request->input('message');
        $conversationId = $request->input('conversation_id');
        $documentContextRaw = $request->input('document_context');
        $documentContext = is_array($documentContextRaw)
            ? (string) ($documentContextRaw['document'] ?? '')
            : $documentContextRaw;

        // Check if conversation is already in content_planner mode - bypass classification
        if ($conversationId) {
            $conversation = \App\Models\AiCanvasConversation::find($conversationId);
            if ($conversation && $conversation->planner_mode === 'content_planner') {
                Log::info('ai.classify-intent.planner_mode_bypass', [
                    'conversation_id' => $conversationId,
                    'planner_mode' => $conversation->planner_mode,
                    'message_preview' => mb_substr($message, 0, 120),
                ]);
                
                return response()->json([
                    'read' => false,
                    'write' => false,
                    'mode' => 'content_planner',
                    'submode' => null,
                    'confidence' => 1.0,
                ]);
            }
        }

        // Build classification prompt per spec
        $prompt = $this->buildClassificationPrompt($message, $documentContext);

        // Call OpenRouter with classifier model
        $result = $openRouter->classify([
            [
                'role' => 'system',
                'content' => 'You are a classification engine. Analyze messages and return JSON with read, write, mode, submode, and confidence fields.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ]);

        // Extract and validate fields
        $read = (bool) ($result['read'] ?? false);
        $write = (bool) ($result['write'] ?? false);
        $mode = (string) ($result['mode'] ?? 'generate');
        $submode = isset($result['submode']) && $result['submode'] !== null ? (string) $result['submode'] : null;
        $confidence = (float) ($result['confidence'] ?? 0.0);

        // Safety guard: Write-intent veto
        if ($write && $mode === 'research') {
            Log::error('ai.classify-intent.invalid', [
                'reason' => 'write=true with mode=research is invalid',
                'message_preview' => mb_substr($message, 0, 120),
                'classifier_result' => $result,
            ]);
            throw new \RuntimeException('Invalid classification: write requests cannot be research mode');
        }

        // Safety guard: Content planner write-intent veto
        if ($write && $mode === 'content_planner') {
            Log::error('ai.classify-intent.invalid', [
                'reason' => 'write=true with mode=content_planner is invalid',
                'message_preview' => mb_substr($message, 0, 120),
                'classifier_result' => $result,
            ]);
            throw new \RuntimeException('Invalid classification: write requests cannot be content_planner mode');
        }

        // Safety guard: Research must have submode
        if ($mode === 'research' && $submode === null) {
            Log::error('ai.classify-intent.invalid', [
                'reason' => 'mode=research requires submode',
                'message_preview' => mb_substr($message, 0, 120),
                'classifier_result' => $result,
            ]);
            throw new \RuntimeException('Invalid classification: research mode requires submode');
        }

        // Safety guard: Confidence threshold warning
        if ($mode === 'research' && $confidence < 0.7) {
            Log::warning('ai.classify-intent.low_confidence', [
                'mode' => $mode,
                'submode' => $submode,
                'confidence' => $confidence,
                'message_preview' => mb_substr($message, 0, 120),
            ]);
        }

        Log::info('ai.classify-intent', [
            'message_preview' => mb_substr($message, 0, 120),
            'has_context' => (bool) $documentContext,
            'read' => $read,
            'write' => $write,
            'mode' => $mode,
            'submode' => $submode,
            'confidence' => $confidence,
        ]);

        return response()->json([
            'read' => $read,
            'write' => $write,
            'mode' => $mode,
            'submode' => $submode,
            'confidence' => $confidence,
        ]);
    }

    /**
     * Generate Chat Response with optional document editing
     */
    public function generateChatResponse(Request $request, OpenRouterService $openRouter, Retriever $retriever, ContentGeneratorService $generator): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'sometimes|string',
            'conversation_history' => 'nullable|array',
            'conversation_history.*.role' => 'required|in:user,assistant,system',
            // Allow empty assistant messages from prior errors; still enforce string type
            'conversation_history.*.content' => 'sometimes|string',
            // Document context may be a string or an object { document, references }
            'document_context' => 'nullable',
            'document_context.document' => 'sometimes|string|max:50000',
            'document_context.references' => 'sometimes|array|max:10',
            'document_context.references.*.type' => 'required_with:document_context.references|string|in:bookmark,file,snippet,url,template,swipe,swipe_structure,fact,voice',
            'document_context.references.*.id' => 'sometimes|string',
            'document_context.references.*.name' => 'sometimes|string',
            'document_context.references.*.title' => 'sometimes|string',
            'document_context.references.*.content' => 'sometimes|nullable|string',
            'document_context.references.*.url' => 'sometimes|url',
            // Backwards-compatible alias for embedded references
            'references' => 'sometimes|array|max:10',
            'references.*.type' => 'required_with:references|string|in:bookmark,file,snippet,url,template,swipe,swipe_structure,fact,voice',
            'references.*.id' => 'sometimes|string',
            'references.*.name' => 'sometimes|string',
            'references.*.title' => 'sometimes|string',
            'references.*.content' => 'sometimes|nullable|string',
            'references.*.url' => 'sometimes|url',
            // New: accept generation options to enforce constraints in chat
            'options' => 'sometimes|array',
            'options.max_chars' => 'sometimes|integer|min:50|max:4000',
            'options.emoji' => 'sometimes|string|in:allow,disallow',
            'options.tone' => 'sometimes|string|max:100',
            'options.intent' => 'sometimes|string|in:educational,persuasive,emotional,story,contrarian',
            'options.funnel_stage' => 'sometimes|string|in:tof,mof,bof',
            'options.voice_profile_id' => 'sometimes|string',
            'options.voice_inline' => 'sometimes|string|max:2000',
            'options.use_retrieval' => 'sometimes|boolean',
            'options.retrieval_limit' => 'sometimes|integer|min:0|max:20',
            'options.use_business_facts' => 'sometimes|boolean',
            'options.mode' => 'sometimes|string|in:generate,research',
            'options.research_stage' => 'sometimes|string|in:deep_research,angle_hooks,trend_discovery,saturation_opportunity',
            'options.swipe_mode' => 'sometimes|string|in:auto,none,strict',
            'options.swipe_ids' => 'sometimes|array|max:10',
            'options.swipe_ids.*' => 'sometimes|string',
            'options.template_id' => 'sometimes|string',
            'options.context_token_budget' => 'sometimes|integer|min:200|max:8000',
            'options.business_context' => 'sometimes|string|max:20000',
            'platform' => 'sometimes|string|max:50',
        ]);

        $message = (string) $request->input('message');
        $conversationId = $request->input('conversation_id');
        $conversationHistory = (array) $request->input('conversation_history', []);
        $documentContext = $request->input('document_context');
        $options = (array) $request->input('options', []);
        $platform = (string) ($request->input('platform') ?? 'generic');

        // Extract document context for classification
        $docContextForClassification = null;
        if (is_array($documentContext)) {
            $docContextForClassification = isset($documentContext['document']) ? (string) $documentContext['document'] : null;
        } elseif (is_string($documentContext)) {
            $docContextForClassification = $documentContext;
        }

        // Check if conversation is already in content_planner mode - bypass classification
        $classificationResult = null;
        if ($conversationId) {
            $conversation = \App\Models\AiCanvasConversation::find($conversationId);
            if ($conversation && $conversation->planner_mode === 'content_planner') {
                Log::info('ai.chat.planner_mode_bypass', [
                    'conversation_id' => $conversationId,
                    'planner_mode' => $conversation->planner_mode,
                    'message_preview' => mb_substr($message, 0, 120),
                ]);
                
                $classificationResult = [
                    'read' => false,
                    'write' => false,
                    'mode' => 'content_planner',
                    'submode' => null,
                    'confidence' => 1.0,
                ];
            }
        }

        // HARD RULE: Call classify-intent to get authoritative decision (only if not bypassed)
        // No overrides allowed. Classifier is the single source of truth.
        if ($classificationResult === null) {
            $classificationPrompt = $this->buildClassificationPrompt($message, $docContextForClassification);
            $classificationResult = $openRouter->classify([
                [
                    'role' => 'system',
                    'content' => 'You are a classification engine. Analyze messages and return JSON with read, write, mode, submode, and confidence fields.',
                ],
                [
                    'role' => 'user',
                    'content' => $classificationPrompt,
                ],
            ]);
        }

        $classifiedMode = (string) ($classificationResult['mode'] ?? 'generate');
        $classifiedSubmode = isset($classificationResult['submode']) && $classificationResult['submode'] !== null 
            ? (string) $classificationResult['submode'] 
            : null;
        $classifiedConfidence = (float) ($classificationResult['confidence'] ?? 0.0);

        // Apply classifier decision (no overrides)
        $options['mode'] = $classifiedMode;
        if ($classifiedSubmode !== null) {
            $options['research_stage'] = $classifiedSubmode;
        }

        Log::info('ai.chat.classification_applied', [
            'message_preview' => mb_substr($message, 0, 120),
            'classified_mode' => $classifiedMode,
            'classified_submode' => $classifiedSubmode,
            'confidence' => $classifiedConfidence,
        ]);

        // Normalize document context into text and embedded references
        $docText = null;
        $docEmbeddedReferences = [];
        if (is_array($documentContext)) {
            $docText = isset($documentContext['document']) ? (string) $documentContext['document'] : null;
            $docEmbeddedReferences = (array) ($documentContext['references'] ?? []);
        } elseif (is_string($documentContext)) {
            $docText = $documentContext;
        }
        $topLevelReferences = $request->input('references');
        if (is_array($topLevelReferences)) {
            $docEmbeddedReferences = array_merge($docEmbeddedReferences, $topLevelReferences);
        }

        Log::info('ai.chat.request', [
            'message_preview' => mb_substr($message, 0, 120),
            'has_context' => (bool) $docText,
            'history_count' => count($conversationHistory),
            'embedded_refs_count' => is_array($docEmbeddedReferences) ? count($docEmbeddedReferences) : 0,
            'mode' => $options['mode'] ?? 'generate',
            'research_stage' => $options['research_stage'] ?? null,
        ]);

        // Extract embedded references and build overrides (VIP behavior)
        $resolvedReferences = [];
        $overrides = [ 'template_id' => null, 'knowledge' => [], 'swipes' => [], 'facts' => [] ];
        $voiceProfileIdOpt = null; // map voice reference to options
        $voiceInlineOpt = null;
        if (!empty($docEmbeddedReferences) && is_array($docEmbeddedReferences)) {
            foreach ($docEmbeddedReferences as $ref) {
                $type = (string) ($ref['type'] ?? 'bookmark');
                $id = $ref['id'] ?? null;
                $title = $ref['title'] ?? $ref['name'] ?? null;
                $contentStr = isset($ref['content']) ? (string) $ref['content'] : '';

                // Build human-readable resolved chunk for prompt context
                if ($contentStr !== '') {
                    $resolvedReferences[] = [
                        'label' => (string) ($title ?? $id ?? 'reference'),
                        'type' => $type,
                        'content' => mb_substr($contentStr, 0, 10 * 1024),
                    ];
                }

                // Build overrides according to reference type
                switch ($type) {
                    case 'template':
                        if (!empty($id)) { $overrides['template_id'] = $id; }
                        break;
                    case 'swipe':
                    case 'swipe_structure':
                        if (!empty($id)) { $overrides['swipes'][] = $id; }
                        break;
                    case 'fact':
                        if (!empty($id)) { $overrides['facts'][] = $id; }
                        break;
                    case 'voice':
                        // Prefer profile id when present; otherwise map inline content to voice_inline
                        if (!empty($id)) {
                            $voiceProfileIdOpt = (string) $id;
                        } elseif ($contentStr !== '') {
                            $voiceInlineOpt = mb_substr($contentStr, 0, 2000);
                        }
                        break;
                    case 'bookmark':
                    case 'file':
                    case 'snippet':
                    case 'url':
                    default:
                        if ($contentStr !== '') {
                            $overrides['knowledge'][] = [
                                'id' => $id,
                                'type' => $type,
                                'title' => $title,
                                'content' => $contentStr,
                            ];
                        }
                        break;
                }
            }
        }

        // Use the unified ContentGeneratorService for both CREATE and EDIT modes.
        $organization = $request->attributes->get('organization');
        $user = $request->user();

        // Build user_context from document + embedded references
        $userContextStr = '';
        if ($docText && trim($docText) !== '') {
            $userContextStr .= "CURRENT_DOCUMENT:\n" . $docText . "\n\n";
        }
        if (!empty($resolvedReferences)) {
            $userContextStr .= "REFERENCES:\n";
            foreach ($resolvedReferences as $ref) {
                $label = (string) ($ref['label'] ?? 'reference');
                $content = (string) ($ref['content'] ?? '');
                if ($content !== '') {
                    $userContextStr .= "- {$label}: \n" . $content . "\n\n";
                }
            }
        }

        // Keep chat snappy: limit retrieval depth for generate mode only
        $requestMode = (string) ($options['mode'] ?? 'generate');
        if ($requestMode !== 'research') {
            $options['retrieval_limit'] = 3;
        }

        // Handle content_planner mode separately
        if ($requestMode === 'content_planner') {
            return $this->handleContentPlannerMode($request, $message, $conversationId, $organization, $user);
        }

        $options['user_context'] = ($options['user_context'] ?? '') . $userContextStr;
        if (!empty($conversationId)) { $options['conversation_id'] = (string) $conversationId; }
        if (!empty($overrides)) { $options['overrides'] = $overrides; }
        if (!empty($voiceProfileIdOpt)) { $options['voice_profile_id'] = $voiceProfileIdOpt; }
        if (!empty($voiceInlineOpt)) { $options['voice_inline'] = $voiceInlineOpt; }

        $result = $generator->generate(
            orgId: (string) $organization->id,
            userId: (string) $user->id,
            prompt: $message,
            platform: $platform,
            options: $options,
        );
        // Persist active context back to the conversation when provided
        if (!empty($conversationId)) {
            try {
                $contextUsed = (array) ($result['context_used'] ?? []);
                $metadata = (array) ($result['metadata'] ?? []);
                $templateId = $metadata['template_id'] ?? null;
                \App\Models\AiCanvasConversation::where('id', (string) $conversationId)->update([
                    'last_message_at' => now(),
                    // Prefer snapshot_id (actual DB id) over run_id (legacy)
                    'last_snapshot_id' => (string) ($metadata['snapshot_id'] ?? $metadata['run_id'] ?? ''),
                    'active_voice_profile_id' => $contextUsed['voice_profile_id'] ?? null,
                    // Only store template_id if it's a valid UUID (fallback templates use string identifiers)
                    'active_template_id' => ($templateId && Str::isUuid($templateId)) ? $templateId : null,
                    'active_swipe_ids' => (array) ($contextUsed['swipe_ids'] ?? []),
                    'active_fact_ids' => (array) ($contextUsed['fact_ids'] ?? []),
                    'active_reference_ids' => (array) ($contextUsed['reference_ids'] ?? []),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Non-fatal; response continues even if persistence fails
                Log::warning('ai.chat.conversation_persist_failed', ['error' => $e->getMessage()]);
            }
        }

        $content = (string) ($result['content'] ?? '');
        $metadata = (array) ($result['metadata'] ?? []);
        
        // Extract mode - handle both old flat format and new object format
        $modeData = $metadata['mode'] ?? ($options['mode'] ?? 'generate');
        if (is_array($modeData)) {
            $effectiveMode = (string) ($modeData['type'] ?? 'generate');
            $researchStage = (string) ($modeData['subtype'] ?? '');
        } else {
            $effectiveMode = (string) $modeData;
            $researchStage = (string) ($metadata['research_stage'] ?? '');
        }
        
        $commandStripped = false;

        if ($effectiveMode === 'research') {
            $commandStripped = true;
            Log::info('ai.chat.mode', [
                'request_mode' => (string) ($options['mode'] ?? 'generate'),
                'effective_mode' => $effectiveMode,
                'research_stage' => $researchStage,
                'command_stripped' => $commandStripped,
            ]);

            return response()->json([
                'response' => 'Here is your research report.',
                'command' => null,
                'report' => (array) ($result['report'] ?? []),
                'metadata' => $metadata,
            ]);
        }

        Log::info('ai.chat.mode', [
            'request_mode' => (string) ($options['mode'] ?? 'generate'),
            'effective_mode' => $effectiveMode,
            'research_stage' => $researchStage,
            'command_stripped' => $commandStripped,
        ]);

        $responseMsg = $docText ? 'Applied your request to the document.' : 'Created content using your knowledge and constraints.';
        $generationContext = $this->buildGenerationContext($result);

        return response()->json([
            'response' => $responseMsg,
            'command' => [
                'action' => 'replace_document',
                'target' => null,
                'content' => $content,
            ],
            'generation_context' => $generationContext,
        ]);
    }

    /**
     * Build classification prompt for READ/WRITE detection + Mode/Submode
     */
    private function buildClassificationPrompt(string $message, ?string $documentContext): string
    {
        $prompt = "You are a classification engine. Analyze the user's message and decide ALL fields together:\n\n";
        $prompt .= "FIELDS TO DECIDE:\n";
        $prompt .= "1. read: Does this require reading the current document? (true/false)\n";
        $prompt .= "2. write: Does the user want to write/modify content? (true/false)\n";
        $prompt .= "3. mode: Execution mode - 'generate', 'research', or 'content_planner' (string)\n";
        $prompt .= "4. submode: Research sub-type - 'deep_research', 'angle_hooks', or null (string|null)\n";
        $prompt .= "5. confidence: How certain are you? (0.0 to 1.0)\n\n";

        $prompt .= "CRITICAL RULES:\n";
        $prompt .= "- Editing, rewriting, updating, creating → mode = 'generate', submode = null\n";
        $prompt .= "- Research, analysis, investigation → mode = 'research', write = false\n";
        $prompt .= "- Content planning, multi-day planning, structured planning, 'create a content plan', 'plan content' → mode = 'content_planner', write = false\n";
        $prompt .= "- Requesting hooks, angles, or ideas (without 'write' or 'create') → mode = 'research', submode = 'angle_hooks'\n";
        $prompt .= "- Asking whether a topic/angle is saturated, overdone, still worth pursuing, or where white space exists → mode = 'research', submode = 'saturation_opportunity'\n";
        $prompt .= "- If mode = 'research', you MUST select exactly one submode\n";
        $prompt .= "- If write = true, mode MUST be 'generate' (never research or content_planner)\n";
        $prompt .= "- If mode = 'content_planner', write MUST be false\n";
        $prompt .= "- deep_research: analysis, evidence, pros/cons, synthesis, 'what are people saying', trends\n";
        $prompt .= "- angle_hooks: hooks, angles, headlines, openers, 'give me ideas'\n";
        $prompt .= "- saturation_opportunity: saturation analysis, opportunity assessment, white space detection, 'is X saturated', 'is this worth doing'\n";
        $prompt .= "- Trend discovery NOT supported yet (treat as deep_research if unclear)\n\n";

        $prompt .= "User message: \"{$message}\"\n\n";

        if ($documentContext && trim($documentContext) !== '') {
            $preview = mb_substr($documentContext, 0, 200);
            $prompt .= "Current document preview:\n```\n{$preview}...\n```\n\n";
        } else {
            $prompt .= "Note: Document is currently EMPTY.\n\n";
        }

        $prompt .= "Return ONLY valid JSON in this exact format:\n";
        $prompt .= '{"read": true/false, "write": true/false, "mode": "generate"|"research"|"content_planner", "submode": "deep_research"|"angle_hooks"|null, "confidence": 0.0-1.0}' . "\n\n";

        $prompt .= "EXAMPLES:\n\n";
        $prompt .= '"What do you think about this poem?" -> {"read": true, "write": false, "mode": "generate", "submode": null, "confidence": 0.9}' . "\n";
        $prompt .= '"Summarize the document" -> {"read": true, "write": false, "mode": "generate", "submode": null, "confidence": 0.95}' . "\n";
        $prompt .= '"Rewrite the introduction" -> {"read": true, "write": true, "mode": "generate", "submode": null, "confidence": 0.98}' . "\n";
        $prompt .= '"Make it shorter" -> {"read": true, "write": true, "mode": "generate", "submode": null, "confidence": 0.95}' . "\n";
        $prompt .= '"Write a blog post about AI" -> {"read": false, "write": true, "mode": "generate", "submode": null, "confidence": 0.99}' . "\n";
        $prompt .= '"Update this post: we have raised £157,000" -> {"read": false, "write": true, "mode": "generate", "submode": null, "confidence": 0.98}' . "\n";
        $prompt .= '"Research what is trending in creator tools" -> {"read": false, "write": false, "mode": "research", "submode": "deep_research", "confidence": 0.85}' . "\n";
        $prompt .= '"Analyze the document for gaps" -> {"read": true, "write": false, "mode": "research", "submode": "deep_research", "confidence": 0.9}' . "\n";
        $prompt .= '"Give me 5 hooks for AI tools" -> {"read": false, "write": false, "mode": "research", "submode": "angle_hooks", "confidence": 0.92}' . "\n";
        $prompt .= '"Is AI video content saturated for SaaS founders?" -> {"read": false, "write": false, "mode": "research", "submode": "saturation_opportunity", "confidence": 0.88}' . "\n";
        $prompt .= '"Is it still worth posting about no-code tools?" -> {"read": false, "write": false, "mode": "research", "submode": "saturation_opportunity", "confidence": 0.85}' . "\n";
        $prompt .= '"Where is the white space in AI marketing content?" -> {"read": false, "write": false, "mode": "research", "submode": "saturation_opportunity", "confidence": 0.9}' . "\n";
        $prompt .= '"Create a content plan for the next 7 days" -> {"read": false, "write": false, "mode": "content_planner", "submode": null, "confidence": 0.95}' . "\n";
        $prompt .= '"Help me plan content for my product launch" -> {"read": false, "write": false, "mode": "content_planner", "submode": null, "confidence": 0.9}' . "\n";
        $prompt .= '"Hello" -> {"read": false, "write": false, "mode": "generate", "submode": null, "confidence": 0.7}' . "\n";

        return $prompt;
    }

    /**
     * Build system prompt with document context and JSON schema
     */
    private function buildSystemPrompt(
        ?string $documentContext,
        array $references = [],
        array $knowledgeChunks = [],
        array $businessFacts = []
    ): string
    {
        $prompt = "You are an AI writing assistant that helps users create and edit documents.\n\n";

        // Inject retrieved knowledge from the knowledge base
        if (!empty($knowledgeChunks) || !empty($businessFacts)) {
            $prompt .= "=== KNOWLEDGE BASE (CONTEXT) ===\n";
            $prompt .= "Use this retrieved information to answer questions or write content. Prefer these facts over assumptions. Do not invent details that contradict them.\n\n";

            if (!empty($businessFacts)) {
                $prompt .= "--- CORE FACTS ---\n";
                foreach ($businessFacts as $fact) {
                    $text = (string) ($fact['text'] ?? '');
                    if ($text !== '') {
                        $prompt .= "- {$text}\n";
                    }
                }
                $prompt .= "\n";
            }

            if (!empty($knowledgeChunks)) {
                $prompt .= "--- RELEVANT NOTES ---\n";
                foreach ($knowledgeChunks as $chunk) {
                    $text = (string) ($chunk['chunk_text'] ?? '');
                    if ($text !== '') {
                        $prompt .= "> {$text}\n";
                    }
                }
                $prompt .= "\n";
            }

            $prompt .= "======================================\n\n";
        }

        // Inject reference materials (read-only) before the editable canvas
        if (!empty($references)) {
            $prompt .= "=== REFERENCE MATERIALS (READ-ONLY) ===\n\n";
            $prompt .= "The user has provided the following reference materials for context.\n";
            $prompt .= "You may read and analyze these, but you must NOT modify them.\n\n";

            foreach ($references as $ref) {
                $label = (string) ($ref['label'] ?? 'reference');
                $content = (string) ($ref['content'] ?? '');
                if ($content !== '') {
                    $prompt .= "--- REFERENCE: {$label} ---\n";
                    $prompt .= $content . "\n\n";
                }
            }

            $prompt .= "======================================\n\n";
        }

        if ($documentContext && trim($documentContext) !== '') {
            // Document exists - READ/EDIT mode
            $prompt .= "=== CURRENT DOCUMENT ===\n";
            $prompt .= "```markdown\n{$documentContext}\n```\n\n";
            $prompt .= "=== YOUR CAPABILITIES ===\n";
            $prompt .= "- You CAN see and reference this document in your responses\n";
            $prompt .= "- For questions/discussion: Respond naturally in plain text\n";
            $prompt .= "- For edit requests: Return a JSON object with a command\n";
            $prompt .= "- Do NOT wrap JSON in code fences; return raw JSON only\n\n";

            $prompt .= "=== JSON FORMAT (when editing) ===\n";
            $prompt .= "{\n";
            $prompt .= '  "response": "Brief explanation of what you did",' . "\n";
            $prompt .= '  "command": {' . "\n";
            $prompt .= '    "action": "replace_document | replace_section | insert_content",' . "\n";
            $prompt .= '    "target": "## Section Name (optional)",' . "\n";
            $prompt .= '    "content": "The new/modified content"' . "\n";
            $prompt .= "  }\n";
            $prompt .= "}\n\n";

            $prompt .= "=== EXAMPLES ===\n\n";
            $prompt .= "User: \"What do you think about this?\"\n";
            $prompt .= "You: \"This is a well-written piece that...\" (plain text response)\n\n";
            $prompt .= "User: \"Rewrite the introduction to be more engaging\"\n";
            $prompt .= 'You: {"response": "I\' . "ve rewritten the introduction...", "command": {"action": "replace_section", "target": "## Introduction", "content": "..."}} (JSON response)' . "\n\n";
            $prompt .= "User: \"Make it shorter\"\n";
            $prompt .= 'You: {"response": "I\' . "ve condensed the document...", "command": {"action": "replace_document", "content": "..."}} (JSON response)';
        } else {
            // Document is empty - CREATE mode
            $prompt .= "=== DOCUMENT STATUS ===\n";
            $prompt .= "The document is currently EMPTY.\n\n";

            $prompt .= "=== YOUR TASK ===\n";
            $prompt .= "When the user asks you to create, write, or update content:\n";
            $prompt .= "- Generate the content from scratch\n";
            $prompt .= "- Return it as a JSON object with a 'replace_document' command\n";
            $prompt .= "- Do NOT wrap JSON in code fences; return raw JSON only\n\n";

            $prompt .= "=== JSON FORMAT (required for empty documents) ===\n";
            $prompt .= "{\n";
            $prompt .= '  "response": "I\' . "ve created [description]",' . "\n";
            $prompt .= '  "command": {' . "\n";
            $prompt .= '    "action": "replace_document",' . "\n";
            $prompt .= '    "target": null,' . "\n";
            $prompt .= '    "content": "# Title\\n\\nYour content here..."' . "\n";
            $prompt .= "  }\n";
            $prompt .= "}\n\n";

            $prompt .= "=== EXAMPLES ===\n\n";
            $prompt .= "User: \"Write a poem about AI\"\n";
            $prompt .= 'You: {"response": "I\' . "ve created a poem about AI", "command": {"action": "replace_document", "target": null, "content": "# AI Dreams\\n\\nIn circuits deep..."}} (JSON response)\n\n';
            $prompt .= "User: \"Update the document to be a nice poem by Dante\"\n";
            $prompt .= 'You: {"response": "I\' . "ve created a Dante-style poem", "command": {"action": "replace_document", "target": null, "content": "# The Divine Path\\n\\nThrough darkened wood..."}} (JSON response)';
        }

        return $prompt;
    }

    /**
     * Parse AI response - detect if JSON command or natural text
     */
    private function parseResponse(string $response): array
    {
        $trimmed = trim($response);

        // Pattern 1: Try to extract JSON from markdown code blocks
        // Matches: ```json\n{...}\n``` or ```\n{...}\n```
        if (preg_match('/```(?:json)?\s*\n(\{[\s\S]*?\})\s*\n```/m', (string) $trimmed, $matches)) {
            $jsonContent = trim((string) $matches[1]);
            try {
                $decoded = json_decode($jsonContent, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['response'])) {
                    Log::info('ai.chat.json_from_codeblock');
                    return [
                        'response' => (string) ($decoded['response'] ?? ''),
                        'command' => $decoded['command'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('ai.chat.json_codeblock_parse_failed', [
                    'error' => $e->getMessage(),
                    'content_preview' => mb_substr($jsonContent, 0, 120),
                ]);
            }
        }

        // Pattern 2: Check if response starts with JSON (no markdown wrapper)
        if (str_starts_with($trimmed, '{')) {
            try {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['response'])) {
                    Log::info('ai.chat.json_direct');
                    return [
                        'response' => (string) ($decoded['response'] ?? ''),
                        'command' => $decoded['command'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('ai.chat.json_parse_failed', [
                    'error' => $e->getMessage(),
                    'preview' => mb_substr($trimmed, 0, 120),
                ]);
            }
        }

        // Pattern 3: Search anywhere in the string for a JSON object
        if (preg_match('/(\{(?:[^{}]|(?R))*\})/s', $trimmed, $m)) {
            $candidate = (string) $m[1];
            try {
                $decoded = json_decode($candidate, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['response'])) {
                    Log::info('ai.chat.json_found_anywhere');
                    return [
                        'response' => (string) ($decoded['response'] ?? ''),
                        'command' => $decoded['command'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('ai.chat.json_anywhere_parse_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: treat as natural text
        Log::info('ai.chat.natural_text');
        return [
            'response' => $response,
            'command' => null,
        ];
    }

    /**
     * Handle content planner mode - deterministic state machine
     */
    private function handleContentPlannerMode(Request $request, string $message, $conversationId, $organization, $user): JsonResponse
    {
        $planner = app(\App\Services\ContentPlannerService::class);

        // Load or create conversation
        if (!$conversationId) {
            return response()->json([
                'error' => 'conversation_id is required for content planner mode',
            ], 400);
        }

        $conversation = \App\Models\AiCanvasConversation::where('id', (string) $conversationId)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        // Check if planner is initialized
        if (!$conversation->planner_mode || $conversation->planner_mode !== 'content_planner') {
            // Initialize planner
            $result = $planner->initializePlanner($conversation);
            
            return response()->json([
                'response' => $result['question'] ?? 'Let\'s create a content plan.',
                'command' => null,
                'planner' => $result,
            ]);
        }

        // Process answer
        $result = $planner->processAnswer($conversation, $message);

        Log::info('ai.chat.content_planner', [
            'conversation_id' => $conversationId,
            'status' => $result['status'] ?? null,
            'question_index' => $result['question_index'] ?? null,
        ]);

        // Handle confirmation action
        if (($result['status'] ?? null) === 'confirmed' && ($result['action'] ?? null) === 'confirm') {
            // Create content plan
            $answers = $result['answers'] ?? [];
            $planType = $answers['plan_type'] ?? 'build_in_public';
            $platform = $answers['platform'] ?? 'twitter';
            $durationDays = (int) ($answers['duration_days'] ?? 7);
            
            // Generate a descriptive name for the plan
            $planName = ucwords(str_replace('_', ' ', $planType)) . ' - ' . ucfirst($platform) . ' (' . $durationDays . ' days)';
            
            // Create plan as draft first (required for BlueprintService)
            $plan = \App\Models\ContentPlan::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'name' => $planName,
                'plan_type' => $planType,
                'duration_days' => $durationDays,
                'platform' => $platform,
                'goal' => $answers['goal'] ?? null,
                'audience' => $answers['audience'] ?? null,
                'voice_profile_id' => null, // Will be handled later based on voice_mode
                'status' => 'draft',
            ]);

            // Generate stages using BlueprintService (US-005)
            $blueprintService = app(\App\Services\BlueprintService::class);
            $blueprintService->generateStages($plan);

            // Confirm the plan after stages are generated
            $plan->update(['status' => 'confirmed']);

            // Queue generation job for posts (US-006)
            dispatch(new \App\Jobs\GenerateContentPlanJob($plan->id));

            Log::info('ai.chat.content_planner.confirmed', [
                'conversation_id' => $conversationId,
                'plan_id' => $plan->id,
            ]);

            return response()->json([
                'response' => $result['message'] ?? 'Content plan created successfully!',
                'command' => null,
                'planner' => [
                    'status' => 'confirmed',
                    'plan_id' => $plan->id,
                ],
            ]);
        }

        return response()->json([
            'response' => $result['message'] ?? $result['question'] ?? 'Processing...',
            'command' => null,
            'planner' => $result,
        ]);
    }

    /**
     * Build rich generation context metadata for frontend display.
     * Returns template/voice names, element counts, and snapshot_id for replay.
     */
    private function buildGenerationContext(array $result): ?array
    {
        $contextUsed = (array) ($result['context_used'] ?? []);
        $metadata = (array) ($result['metadata'] ?? []);

        // No generation context if no snapshot was created
        // Prefer snapshot_id (actual DB id) over run_id (legacy/fallback)
        $snapshotId = $metadata['snapshot_id'] ?? $metadata['run_id'] ?? null;
        if (!$snapshotId) {
            return null;
        }

        // Resolve template name
        $templateId = $contextUsed['template_id'] ?? null;
        $templateName = null;
        if ($templateId && Str::isUuid($templateId)) {
            try {
                $template = \App\Models\Template::find($templateId);
                $templateName = $template?->name ?? null;
            } catch (\Throwable) {}
        }

        // Resolve voice profile name
        $voiceProfileId = $contextUsed['voice_profile_id'] ?? null;
        $voiceName = null;
        if ($voiceProfileId) {
            try {
                $voice = \App\Models\VoiceProfile::find($voiceProfileId);
                $voiceName = $voice?->name ?? null;
            } catch (\Throwable) {}
        }

        $chunkIds = (array) ($contextUsed['chunk_ids'] ?? []);
        $factIds = (array) ($contextUsed['fact_ids'] ?? []);
        $swipeIds = (array) ($contextUsed['swipe_ids'] ?? []);

        return [
            'snapshot_id' => $snapshotId,
            'template' => $templateId ? [
                'id' => $templateId,
                'name' => $templateName,
            ] : null,
            'voice' => $voiceProfileId ? [
                'id' => $voiceProfileId,
                'name' => $voiceName,
            ] : null,
            'chunks_count' => count($chunkIds),
            'facts_count' => count($factIds),
            'swipes_count' => count($swipeIds),
            'intent' => $metadata['intent'] ?? null,
            'funnel_stage' => $metadata['funnel_stage'] ?? null,
        ];
    }
}
