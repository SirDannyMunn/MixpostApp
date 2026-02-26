<?php




use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\BookmarkController;
use App\Http\Controllers\Api\V1\ContentPlanController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationMemberController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\VoiceProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // DEBUG: Temporary test endpoint to diagnose JSON body parsing
    Route::post('/test-json-body', function (\Illuminate\Http\Request $request) {
        return response()->json([
            'all' => $request->all(),
            'input_versions' => $request->input('versions'),
            'json' => $request->json()->all(),
            'content' => $request->getContent(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
        ]);
    });
    
    // Auth - throttle 5/min as per spec
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });
    
    // OAuth handoff token exchange - public endpoint for Chrome extension
    // Token is one-time use, rate-limited to prevent brute force
    Route::post('/oauth/handoff', [\Inovector\Mixpost\Http\Controllers\Api\OAuthHandoffController::class, 'exchange'])
        ->middleware('throttle:30,1');
    
    // Public AI endpoints (skip authentication per implementation request)
    Route::post('/projects/generate-slideshow', [\App\Http\Controllers\Api\V1\SlideshowController::class, 'generate'])->middleware('throttle:20,1');
    Route::post('/ai/assist-prompt', [\App\Http\Controllers\Api\V1\AiController::class, 'assistPrompt'])->middleware('throttle:30,1');
    Route::post('/projects/{id}/copy', [\App\Http\Controllers\Api\V1\SlideshowController::class, 'copy'])->middleware('throttle:60,1');

    Route::middleware('auth:sanctum,api')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Billing status and checkout (auth-only, no paywall)
        Route::get('/me/billing', \App\Http\Controllers\Api\V1\MeBillingController::class);
        Route::prefix('billing')->group(function () {
            Route::post('/checkout', \App\Http\Controllers\Api\V1\BillingCheckoutController::class);
            // Route::post('/portal', ...) // optional portal endpoint
        });

        // Organization management
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::match(['put','patch'], '/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);

        // Organization members
        Route::get('/organizations/{organization}/members', [OrganizationMemberController::class, 'index']);
        Route::post('/organizations/{organization}/members/invite', [OrganizationMemberController::class, 'invite']);
        Route::patch('/organizations/{organization}/members/{member}', [OrganizationMemberController::class, 'update']);
        Route::delete('/organizations/{organization}/members/{member}', [OrganizationMemberController::class, 'destroy']);

        // Org-scoped resources require organization middleware (+ paywall)
        Route::middleware(['organization','billing.access'])->group(function () {
            // AI Canvas endpoints (protected + org scoped)
            /**
             * AI endpoints (protected + organization scoped)
             *
             * Base path:  POST /api/v1/ai
             * Middleware: auth:sanctum, organization, throttle:ai
             * Rate limit: limiter "ai" (see App\\Providers\\RouteServiceProvider@configureRateLimiting)
             * Context:    organization resolved by App\\Http\\Middleware\\EnsureOrganizationContext
             *
             * Dependencies used by these endpoints:
             * - Controller: App\\Http\\Controllers\\Api\\V1\\AiController
             * - Service:    App\\Services\\OpenRouterService (Guzzle client to OpenRouter APIs)
             *   - Config:  config/services.php ['openrouter' => ['api_key','api_url','chat_model','classifier_model']]
             *   - Env:     OPENROUTER_API_KEY, OPENROUTER_API_URL, OPENROUTER_MODEL, OPENROUTER_CLASSIFIER_MODEL
             * - Logging:   Illuminate\\Support\\Facades\\Log with keys: ai.classify-intent, ai.chat.*, openrouter.*
             *
             * Endpoint:    POST /api/v1/ai/classify-intent
             * Handler:     AiController@classifyIntent (app/Http/Controllers/Api/V1/AiController.php)
             * Request:
             *   {
             *     "message": string (required, <= 5000 chars),
             *     "document_context": string | { "document": string } (optional, <= 50000 chars)
             *   }
             * Behavior:
             *   - Validates payload.
             *   - Builds a classifier prompt via AiController::buildClassificationPrompt(message, documentContext).
             *   - Calls OpenRouterService::classify([...]) which uses the configured classifier model and
             *     response_format json_object to obtain JSON.
             *   - Logs result under 'ai.classify-intent' and returns canonical booleans:
             *       { "read": bool, "write": bool }
             * Key internals:
             *   - OpenRouterService posts to `{api_url}/chat/completions` with model
             *     config('services.openrouter.classifier_model'). Requires OPENROUTER_API_KEY.
             *   - Rate limiting via 'ai' limiter: Limit::perMinute(20) by user id or IP.
             *
             * Endpoint:    POST /api/v1/ai/chat
             * Handler:     AiController@generateChatResponse (app/Http/Controllers/Api/V1/AiController.php)
             * Request:
             *   {
             *     "message": string (required, <= 5000 chars),
             *     "conversation_history": [ { role: "user|assistant|system", content: string } ] (optional),
             *     "document_context": string | {
             *        "document": string (<= 50000 chars),
             *        "references": [ { "type": "bookmark|file|snippet|url", "id"?: string, "name"?: string, "url"?: url, "content"?: string } ]
             *     } (optional)
             *   }
             * Response:
             *   - JSON with either natural chat and optional structured command, e.g.:
             *       { "response": string, "command"?: { "action": "replace_document|replace_section|insert_content", "target"?: string|null, "content": string } }
             * Behavior:
             *   - Normalizes document context into text + embedded references.
             *   - Builds a system prompt with AiController::buildSystemPrompt(document, references).
             *   - Assembles messages: system + last 10 from conversation_history + current user message.
             *   - If document is empty: forces JSON mode via OpenRouterService::chatJSON(messages, ...)
             *     and returns decoded JSON.
             *   - Otherwise: calls OpenRouterService::chat(messages, ...), then parses text with
             *     AiController::parseResponse() which can extract JSON from code blocks, direct JSON, or
             *     embedded JSON; falls back to natural text when no command JSON is found.
             * Key internals:
             *   - OpenRouterService::chat / ::chatJSON use config('services.openrouter.chat_model').
             *   - Requires OPENROUTER_API_KEY and honors OPENROUTER_API_URL; uses Guzzle with optional
             *     proxy envs (HTTP_PROXY/HTTPS_PROXY/NO_PROXY). Logs diagnostics under openrouter.*
             */
            Route::prefix('ai')->middleware('throttle:ai')->group(function () {
                Route::post('/classify-intent', [AiController::class, 'classifyIntent']);
                Route::post('/chat', [AiController::class, 'generateChatResponse']);
                Route::post('/generate-post', [AiController::class, 'generatePost']);
                Route::post('/rewrite-sentence', [AiController::class, 'rewriteSentence']);
                Route::get('/generate-post/{id}', [AiController::class, 'getGeneratedPost']);
            });
            // Organization settings
            Route::get('/organization-settings', [\App\Http\Controllers\Api\V1\OrganizationSettingsController::class, 'index']);
            Route::put('/organization-settings', [\App\Http\Controllers\Api\V1\OrganizationSettingsController::class, 'update']);
            Route::post('/organization-settings/reset', [\App\Http\Controllers\Api\V1\OrganizationSettingsController::class, 'reset']);
            Route::get('/organization-settings/export-for-ai', [\App\Http\Controllers\Api\V1\OrganizationSettingsController::class, 'exportForAI']);
            // Folders
            Route::get('/folders', [FolderController::class, 'index']);
            Route::post('/folders', [FolderController::class, 'store']);
            Route::get('/folders/{folder}', [FolderController::class, 'show']);
            Route::match(['put','patch'], '/folders/{folder}', [FolderController::class, 'update']);
            Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);
            Route::post('/folders/reorder', [FolderController::class, 'reorder']);

            // Tags
            Route::get('/tags', [TagController::class, 'index']);
            Route::post('/tags', [TagController::class, 'store']);
            Route::get('/tags/{tag}', [TagController::class, 'show']);
            Route::match(['put','patch'], '/tags/{tag}', [TagController::class, 'update']);
            Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

            // Bookmarks
            Route::get('/bookmarks', [BookmarkController::class, 'index']);
            Route::post('/bookmarks', [BookmarkController::class, 'store'])->middleware('subscription:bookmarks');
            Route::get('/bookmarks/{bookmark}', [BookmarkController::class, 'show']);
            Route::match(['put','patch'], '/bookmarks/{bookmark}', [BookmarkController::class, 'update']);
            Route::delete('/bookmarks/{bookmark}', [BookmarkController::class, 'destroy']);
            Route::post('/bookmarks/{bookmark}/ingest', [\App\Http\Controllers\Api\V1\IngestionController::class, 'ingestBookmark']);
            // Knowledge + Swipes (AI retrieval sources)
            Route::post('/knowledge-items', [App\Http\Controllers\Api\V1\KnowledgeItemController::class, 'store']);
            Route::post('/swipe-items', [App\Http\Controllers\Api\V1\SwipeItemController::class, 'store']);
            Route::get('/swipe-items', [App\Http\Controllers\Api\V1\SwipeItemController::class, 'index']);
            Route::prefix('knowledge')->group(function () {
                Route::get('/chunks', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'index']);
                Route::get('/chunks/{id}', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'show']);
                Route::post('/chunks/{id}/deactivate', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'deactivate']);
                Route::post('/chunks/{id}/activate', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'activate']);
                Route::post('/chunks/{id}/reclassify', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'reclassify']);
                Route::post('/chunks/{id}/set-policy', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'setPolicy']);
                Route::delete('/chunks/{id}', [\App\Http\Controllers\Api\V1\KnowledgeChunkController::class, 'destroy']);
            });
            Route::prefix('research')->group(function () {
                Route::post('/search', [\App\Http\Controllers\Api\V1\ResearchController::class, 'search'])->middleware('throttle:60,1');
                Route::post('/add-to-knowledge', [\App\Http\Controllers\Api\V1\ResearchController::class, 'addToKnowledge'])->middleware('throttle:30,1');
            });
            // Canonical SwipeStructures CRUD
            Route::get('/swipe-structures', [\App\Http\Controllers\Api\V1\SwipeStructureController::class, 'index']);
            Route::post('/swipe-structures', [\App\Http\Controllers\Api\V1\SwipeStructureController::class, 'store']);
            Route::get('/swipe-structures/{id}', [\App\Http\Controllers\Api\V1\SwipeStructureController::class, 'show']);
            Route::put('/swipe-structures/{id}', [\App\Http\Controllers\Api\V1\SwipeStructureController::class, 'update']);
            Route::delete('/swipe-structures/{id}', [\App\Http\Controllers\Api\V1\SwipeStructureController::class, 'destroy']);
            Route::get('/business-facts', [App\Http\Controllers\Api\V1\BusinessFactController::class, 'index']);

            // AI debugging
            Route::post('/ai/replay-snapshot/{id}', [AiController::class, 'replaySnapshot'])->middleware('throttle:ai');

            // Templates
            Route::get('/templates', [\App\Http\Controllers\Api\V1\TemplateController::class, 'index']);
            Route::post('/templates', [\App\Http\Controllers\Api\V1\TemplateController::class, 'store']);
            Route::post('/templates/parse', [\App\Http\Controllers\Api\V1\TemplateController::class, 'parse']);
            Route::get('/templates/{id}', [\App\Http\Controllers\Api\V1\TemplateController::class, 'show']);
            Route::match(['put','patch'], '/templates/{id}', [\App\Http\Controllers\Api\V1\TemplateController::class, 'update']);
            Route::delete('/templates/{id}', [\App\Http\Controllers\Api\V1\TemplateController::class, 'destroy']);

            // Media library
            Route::get('/media/packs', [\App\Http\Controllers\Api\V1\MediaPackController::class, 'index']);
            Route::post('/media/packs', [\App\Http\Controllers\Api\V1\MediaPackController::class, 'store']);
            Route::match(['put','patch'], '/media/packs/{id}', [\App\Http\Controllers\Api\V1\MediaPackController::class, 'update']);
            Route::delete('/media/packs/{id}', [\App\Http\Controllers\Api\V1\MediaPackController::class, 'destroy']);

            Route::get('/media/images', [\App\Http\Controllers\Api\V1\MediaImageController::class, 'index']);
            Route::post('/media/images/upload', [\App\Http\Controllers\Api\V1\MediaImageController::class, 'upload']);
            Route::post('/media/images/generate', [\App\Http\Controllers\Api\V1\MediaImageController::class, 'generate'])->middleware('throttle:10,1');
            Route::patch('/media/images/{id}', [\App\Http\Controllers\Api\V1\MediaImageController::class, 'update']);
            Route::delete('/media/images/{id}', [\App\Http\Controllers\Api\V1\MediaImageController::class, 'destroy']);

            // Projects
            Route::get('/projects', [\App\Http\Controllers\Api\V1\ProjectController::class, 'index']);
            Route::post('/projects', [\App\Http\Controllers\Api\V1\ProjectController::class, 'store']);
            Route::get('/projects/{id}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'show']);
            Route::match(['put','patch'], '/projects/{id}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'update']);
            Route::delete('/projects/{id}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'destroy']);

            // Social accounts
            Route::get('/social-accounts', [\App\Http\Controllers\Api\V1\SocialAccountController::class, 'index']);
            Route::get('/social-accounts/connect/{platform}', [\App\Http\Controllers\Api\V1\SocialAccountController::class, 'connect']);
            Route::post('/social-accounts', [\App\Http\Controllers\Api\V1\SocialAccountController::class, 'store']);
            Route::delete('/social-accounts/{id}', [\App\Http\Controllers\Api\V1\SocialAccountController::class, 'destroy']);
            
            // OAuth entity selection (for Facebook Pages, etc.)
            Route::get('/oauth/entities', [\Inovector\Mixpost\Http\Controllers\Api\OAuthHandoffController::class, 'getEntities']);
            Route::post('/oauth/entities/select', [\Inovector\Mixpost\Http\Controllers\Api\OAuthHandoffController::class, 'selectEntity']);

            // Scheduled posts
            Route::get('/scheduled-posts', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'index']);
            Route::post('/scheduled-posts', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'store']);
            Route::get('/scheduled-posts/{id}', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'show']);
            Route::match(['put','patch'], '/scheduled-posts/{id}', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'update']);
            Route::post('/scheduled-posts/{id}/cancel', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'cancel']);
            Route::delete('/scheduled-posts/{id}', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'destroy']);
            Route::post('/scheduled-posts/{id}/publish-now', [\App\Http\Controllers\Api\V1\ScheduledPostController::class, 'publishNow']);

            // Analytics
            Route::get('/analytics/overview', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'overview']);
            Route::get('/analytics/accounts/{id}', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'account']);
            Route::get('/analytics/top-content', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'topContent']);

            // Activity log
            Route::get('/activity', [\App\Http\Controllers\Api\V1\ActivityController::class, 'index']);

            // Search
            Route::get('/search', [\App\Http\Controllers\Api\V1\SearchController::class, 'index']);
            // Unified Library API (Phase 1: bookmarks-backed)
            Route::get('/library-items', [\App\Http\Controllers\Api\V1\LibraryItemController::class, 'index']);
            Route::post('/library/bulk-delete', [\App\Http\Controllers\Api\V1\LibraryItemController::class, 'bulkDelete']);

            // Webhooks
            Route::post('/webhooks', [\App\Http\Controllers\Api\V1\WebhookController::class, 'store']);

            // Voice Profiles
            Route::get('/voice-profiles', [VoiceProfileController::class, 'index']);
            Route::post('/voice-profiles', [VoiceProfileController::class, 'store']);
            Route::get('/voice-profiles/{id}', [VoiceProfileController::class, 'show']);
            Route::match(['put','patch'], '/voice-profiles/{id}', [VoiceProfileController::class, 'update']);
            Route::post('/voice-profiles/{id}/rebuild', [VoiceProfileController::class, 'rebuild']);
            Route::get('/voice-profiles/{id}/posts', [VoiceProfileController::class, 'listAttachedPosts']);
            Route::post('/voice-profiles/{id}/posts', [VoiceProfileController::class, 'attachPost']);
            Route::post('/voice-profiles/{id}/posts/batch', [VoiceProfileController::class, 'batchAttachPosts'])->middleware('throttle:10,1');
            Route::post('/voice-profiles/{id}/posts/auto-select', [VoiceProfileController::class, 'autoSelectPosts'])->middleware('throttle:10,1');
            Route::delete('/voice-profiles/{id}/posts/{contentNodeId}', [VoiceProfileController::class, 'detachPost']);

            // Content Plans
            Route::get('/content-plans', [ContentPlanController::class, 'index']);
            Route::post('/content-plans', [ContentPlanController::class, 'store']);
            Route::get('/content-plans/{id}', [ContentPlanController::class, 'show']);
            Route::get('/content-plans/by-conversation/{conversationId}', [ContentPlanController::class, 'getByConversation']);
            Route::post('/content-plans/{id}/confirm', [ContentPlanController::class, 'confirm']);
            Route::post('/content-plans/{id}/regenerate-stages', [ContentPlanController::class, 'regenerateStages']);
            Route::patch('/content-plans/{planId}/posts/{postId}', [ContentPlanController::class, 'updatePost']);

            // AI Canvas Conversations
            Route::prefix('ai-canvas')->group(function () {
                Route::post('/conversations', [\App\Http\Controllers\Api\V1\AiCanvasConversationController::class, 'store']);
                Route::get('/conversations', [\App\Http\Controllers\Api\V1\AiCanvasConversationController::class, 'index']);
                Route::get('/conversations/{id}', [\App\Http\Controllers\Api\V1\AiCanvasConversationController::class, 'show']);
                Route::get('/conversations/{id}/document', [\App\Http\Controllers\Api\V1\AiCanvasConversationController::class, 'document']);
                Route::patch('/conversations/{id}', [\App\Http\Controllers\Api\V1\AiCanvasConversationController::class, 'update']);
                Route::delete('/conversations/{id}', [\App\Http\Controllers\Api\V1\AiCanvasConversationController::class, 'destroy']);

                // Messages
                Route::get('/conversations/{conversationId}/messages', [\App\Http\Controllers\Api\V1\AiCanvasMessageController::class, 'index']);
                Route::post('/conversations/{conversationId}/messages', [\App\Http\Controllers\Api\V1\AiCanvasMessageController::class, 'store']);

                // Versions
                Route::post('/conversations/{conversationId}/versions', [\App\Http\Controllers\Api\V1\AiCanvasVersionController::class, 'store']);
                Route::get('/conversations/{conversationId}/versions', [\App\Http\Controllers\Api\V1\AiCanvasVersionController::class, 'indexForConversation']);
                Route::get('/versions/{versionId}', [\App\Http\Controllers\Api\V1\AiCanvasVersionController::class, 'show']);
                Route::get('/versions/{versionId}/document', [\App\Http\Controllers\Api\V1\AiCanvasVersionController::class, 'document']);
                Route::post('/versions/{versionId}/restore', [\App\Http\Controllers\Api\V1\AiCanvasVersionController::class, 'restore']);
                Route::patch('/versions/{versionId}/media', [\App\Http\Controllers\Api\V1\AiCanvasVersionController::class, 'updateMedia']);
            });
        });
    });
});




// Browser Use proxy routes (auth + organization scoped)
Route::prefix('v1')->middleware(['auth:sanctum,api', 'organization'])->group(function () {
    Route::prefix('browser-use')->group(function () {
        // Sessions
        Route::get('/sessions', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'listSessions']);
        Route::post('/sessions', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'createSession']);
        Route::get('/sessions/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'getSession']);
        Route::patch('/sessions/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'updateSession']);
        Route::delete('/sessions/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'deleteSession']);
        Route::get('/sessions/{id}/public-share', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'getShare']);
        Route::post('/sessions/{id}/public-share', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'createShare']);
        Route::delete('/sessions/{id}/public-share', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'deleteShare']);

        // Tasks
        Route::get('/tasks', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'listTasks']);
        Route::post('/tasks', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'createTask']);
        Route::post('/tasks/bulk', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'createTasksBulk']);
        Route::get('/tasks/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'getTask']);
        Route::patch('/tasks/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'updateTask']);
        Route::get('/tasks/{id}/logs', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'getTaskLogs']);

        // Browsers
        Route::get('/browsers', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'listBrowsers']);
        Route::get('/browsers/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'getBrowser']);
        Route::patch('/browsers/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'updateBrowser']);

        // Profiles
        Route::get('/profiles', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'listProfiles']);
        Route::post('/profiles', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'createProfile']);
        Route::delete('/profiles/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'deleteProfile']);

        // Secrets
        Route::get('/secrets', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'listSecrets']);
        Route::post('/secrets', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'createSecret']);
        Route::get('/secrets/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'getSecret']);
        Route::patch('/secrets/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'updateSecret']);
        Route::delete('/secrets/{id}', [\App\Http\Controllers\Api\V1\BrowserUseController::class, 'deleteSecret']);

        // Proxy management
        Route::get('/proxies', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'index']);
        Route::post('/proxies', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'store']);
        Route::post('/proxies/sync/webshare', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'syncWebshare']);
        Route::get('/proxies/{id}', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'show']);
        Route::patch('/proxies/{id}', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'update']);
        Route::delete('/proxies/{id}', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'destroy']);
        Route::post('/proxies/{id}/assignments', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'createAssignment']);
        Route::patch('/proxy-assignments/{id}', [\App\Http\Controllers\Api\V1\BrowserUseProxyController::class, 'updateAssignment']);
    });
});

// Ingestion Sources routes (auth + organization scoped)
Route::prefix('v1')->middleware(['auth:sanctum,api', 'organization'])->group(function () {
    Route::get('/ingestion-sources', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'index']);
    Route::post('/ingestion-sources', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'store']);
    Route::patch('/ingestion-sources/{id}', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'update']);
        Route::post('/ingestion-sources/{id}/extract-structure', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'extractStructure']);
    Route::delete('/ingestion-sources/{id}', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'destroy']);
    Route::post('/ingestion-sources/{id}/reingest', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'reingest']);
    // Alias for frontend naming: "ingest" = reingest/queue processing
    Route::post('/ingestion-sources/{id}/ingest', [\App\Http\Controllers\Api\V1\IngestionSourceController::class, 'reingest']);
});
