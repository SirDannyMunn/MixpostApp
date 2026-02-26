> Overview                                                                                                                                 
                                                                                                                                           
  - Purpose: Unified AI chat and content generation for social posts and document-style chat editing, backed by intent classification,     
  retrieval, templates, and validation.                                                                                                    
  - Core entry points: app/Http/Controllers/Api/V1/AiController.php (API endpoints) and app/Services/Ai/ContentGeneratorService.php        
  (generation pipeline).                                                                                                                   
  - Key subsystems: Classification, Retrieval, Template selection, Context assembly, LLM orchestration, Validation, and Repair.            
  - Backends: OpenRouter for chat/classification/JSON output; optional OpenAI for slideshow helpers.                                       

  New In This Version                                                                                                                     
                                                                                                                                          
  - Token-budget pruning in `ContextAssembler` with per-category token usage reporting.                                                    
  - Swipe structure similarity: selects swipes by structural "shape" vs. simple keywords.                                                
  - Quality Evaluation Loop: post-generation scoring (relevance, structure, readability, length, emoji).                                  
  - Replayable debugging snapshots: full prompt/context capture + A/B replay with metrics.                                                
                                                                                                                                           
  Key Files                                                                                                                                
                                                                                                                                           
  - Controllers: app/Http/Controllers/Api/V1/AiController.php                                                                              
  - Routes: routes/api.php                                                                                                                 
  - Unified generator: app/Services/Ai/ContentGeneratorService.php                                                                         
  - AI subsystems (content generation):                                                                                                    
      - app/Services/Ai/PostClassifier.php                                                                                                 
      - app/Services/Ai/Retriever.php                                                                                                      
      - app/Services/Ai/TemplateSelector.php                                                                                               
      - app/Services/Ai/ContextAssembler.php                                                                                               
      - app/Services/Ai/GenerationContext.php                                                                                              
      - app/Services/Ai/LLMClient.php                                                                                                      
      - app/Services/Ai/SchemaValidator.php                                                                                                
      - app/Services/Ai/PostValidator.php                                                                                                  
      - app/Services/OpenRouterService.php                                                                                                 
      - app/Services/Ai/SnapshotService.php                                                                                                
      - app/Services/Ai/PostQualityEvaluator.php                                                                                           
      - app/Services/Ai/QualityReportService.php                                                                                           
  - Content ingestion/jobs: app/Jobs/*                                                                                                     
  - Models used: app/Models/* (see Data Models)                                                                                            
                                                                                                                                           
  Routes & Endpoints                                                                                                                       
                                                                                                                                           
  - Base: /api/v1 (see routes/api.php)                                                                                                     
  - Public helpers (no auth):                                                                                                              
      - POST /api/v1/ai/assist-prompt â†’ AiController@assistPrompt                                                                          
      - POST /api/v1/projects/generate-slideshow â†’ slideshow (non-core to content gen)                                                     
  - Authenticated + org-scoped (middleware auth:sanctum, organization, throttle:ai):                                                       
      - POST /api/v1/ai/classify-intent â†’ AiController@classifyIntent                                                                      
          - Input: message (string, <=5000), document_context (string | { document: string }, <=50000)
          - Output: { "read": bool, "write": bool, "mode": "generate"|"research", "submode": "deep_research"|"angle_hooks"|null, "confidence": 0.0-1.0 }
          - **SINGLE AUTHORITATIVE DECISION POINT** - LLM-powered classification determines all fields in one call
          - No downstream overrides allowed - classifier decision is final
          - Safety guards: write=true must be mode=generate, mode=research requires submode
          - All decisions logged to 'ai.classify-intent' with confidence scores
      - POST /api/v1/ai/replay-snapshot/{id} â†’ AiController@replaySnapshot                                                                 
          - Input (all optional except id path):                                                                                            
              - platform (string, <=50)                                                                                                     
              - user_context (string, <=20000)                                                                                              
              - options.max_chars (int 50â€“4000), options.emoji (allow|disallow), options.tone (string), options.context_token_budget (int)  
              - store_report (boolean; default true)                                                                                        
          - Output (JSON):                                                                                                                  
              - snapshot_id                                                                                                                 
              - metadata: { model_used, total_tokens, processing_time_ms, intent, platform }                                                
              - input_snapshot: { template_id, provided_context_items, pruned_items }                                                       
              - output: { content, validation { ok, metrics { char_count, target_max, emoji_count, paragraphs }, issues[] } }               
              - quality_report: { overall_score, breakdown { relevance, structure_adherence, readability, length_fit, emoji_compliance } }  
              - context: { context_source_ids { chunk_ids[], fact_ids[], swipe_ids[] }, token_usage {...}, raw_prompt_sent, system_instruction }
              - debug_links: { view_full_prompt }                                                                                           
      - POST /api/v1/ai/chat â†’ AiController@generateChatResponse                                                                           
          - Input: message (<=5000), conversation_history (array of { role, content }), optional document_context (string | { document,    
  references[] }), options (max_chars, emoji, tone, etc.), platform (string)                                                               
          - Behavior: Builds user context (document + embedded references) â†’ calls unified generator â†’ returns a DocumentUpdate command    
  (see â€œChat + DocumentUpdatesâ€)                                                                                                           
          - Output: { "response": string, "command": { "action": "replace_document", "target": null, "content": string } }                 
      - POST /api/v1/ai/generate-post â†’ AiController@generatePost                                                                          
          - Input: platform (required), prompt (required), optional: context, options{max_chars, cta, emoji, tone}, bookmark_ids[]         
          - Behavior: Creates GeneratedPost row (status queued) and dispatches GeneratePostJob                                             
          - Output: { "generation_id": string, "status": "queued", "limits": { "max_chars", "emoji" } }                                    
      - GET /api/v1/ai/generate-post/{id} â†’ AiController@getGeneratedPost                                                                  
          - Output: { id, status, content, validation }                                                                                    
      - POST /api/v1/ai/rewrite-sentence â†’ AiController@rewriteSentence                                                                    
          - Input: sentence (required), instruction (required), optional rules{emoji, max_chars, tone}, optional generated_post_id         
          - Output: { rewritten_sentence: string, ok: bool }                                                                               
  - Related (template ingestion):                                                                                                          
      - POST /api/v1/templates/parse â†’ parse example text into template schema asynchronously                                              
                                                                                                                                           
  Controllers                                                                                                                              
                                                                                                                                           
  - AiController@assistPrompt:                                                                                                             
      - Validates context, language.                                                                                                       
      - Calls OpenAIService::assistPrompt to produce a starter slideshow prompt JSON.                                                      
  - AiController@classifyIntent:                                                                                                           
      - **LLM-powered classification** - Builds structured prompt and calls OpenRouterService::classify.
      - Prompt instructs LLM to decide ALL fields together: read, write, mode, submode, confidence.
      - Returns canonical {read, write, mode, submode, confidence} in single response.
      - **Single source of truth** - No other service may infer or override these decisions.
      - Safety guards:
          - Write-intent veto: Throws exception if write=true with mode=research (invalid combination)
          - Research requires submode: Throws exception if mode=research with submode=null
          - Confidence threshold: Logs warnings for research classifications with confidence < 0.7
      - All decisions logged to 'ai.classify-intent' for monitoring and tuning.
  - AiController@generatePost:                                                                                                             
      - Validates inputs, creates a GeneratedPost row scoped to the current org/user with request metadata (including reference IDs), and  
  enqueues GeneratePostJob.                                                                                                                
  - AiController@rewriteSentence:                                                                                                          
      - Strong JSON response contract via OpenRouterService::chatJSON with schema sentence_rewrite and one retry on schema failure.        
      - Enforces emoji policy, length, then persists SentenceRewrite.                                                                      
  - AiController@generateChatResponse:                                                                                                     
  - AiController@generateChatResponse:                                                                                                     
      - **Classification first** - Calls classify-intent to get authoritative mode/submode decision (hard rule).
      - Normalizes document_context: accepts raw text or { document, references[] } where references may include embedded content.         
      - Builds consolidated user_context string with document and references.
      - **Hard routing rule** - Respects classifier decision with no overrides:
          - If mode=research â†’ routes to ResearchExecutor with specified submode
          - If mode=generate â†’ routes to ContentGeneratorService with retrieval_limit=3
      - Returns DocumentUpdate (generate mode) or research report (research mode) based on classification.
                                                                                                                                           
  Classification Architecture (January 2026)                                                                                           
                                                                                                                                           
  - **Single Authoritative Decision Point:** /api/v1/ai/classify-intent determines ALL execution parameters in one LLM call.              
  - **LLM-Powered Classification:** Replaces keyword-based heuristics with natural language understanding.                                 
  - **Decision Fields:**                                                                                                                   
      - read: bool - Requires reading current document?                                                                                    
      - write: bool - User wants to write/modify content?                                                                                  
      - mode: "generate"|"research" - Execution mode                                                                                       
      - submode: "deep_research"|"angle_hooks"|null - Research sub-type (null for generate mode)                                         
      - confidence: 0.0-1.0 - Classifier certainty                                                                                         
  - **Classification Logic:**                                                                                                              
      - Editing/rewriting/updating â†’ mode=generate, submode=null                                                                           
      - Research/analysis/investigation â†’ mode=research, write=false                                                                       
      - Hook/angle requests â†’ mode=research, submode=angle_hooks                                                                           
      - If write=true, mode MUST be generate (never research)                                                                              
  - **Submode Semantics:**                                                                                                                 
      - deep_research: Analysis, evidence-based arguments, pros/cons, synthesis, market intelligence                                       
      - angle_hooks: Hooks, angles, headlines, openers, creative ideation                                                                  
  - **Safety Guards:**                                                                                                                     
      - Write-intent veto: Throws error if write=true with mode=research                                                                   
      - Research requires submode: Throws error if mode=research with submode=null                                                         
      - Confidence threshold: Logs warnings if mode=research with confidence < 0.7                                                         
  - **Hard Routing Rule:** Chat controller calls classifier once, respects decision, no overrides.                                         
  - **Observability:** All decisions logged to 'ai.classify-intent' with full context and confidence scores.                              
  - **Deprecated:** Keyword-based ResearchStageClassifier removed (Jan 2026) - replaced by LLM classification.                            
                                                                                                                                           
  Content Generation Pipeline                                                                                                              
                                                                                                                                           
  - Entry: ContentGeneratorService::generate(orgId, userId, prompt, platform, options=[])                                                  
      - Classification: PostClassifier::classify(prompt) â†’ { intent: enum[educational,persuasive,emotional,contrarian,story], funnel_stage:
  enum[tof,mof,bof] } via OpenRouterService::classify, with defaults on failure.                                                           
      - Retrieval: Retriever::knowledgeChunks(...) uses pgvector cosine distance threshold (config/vector.php), per-intent caps; fallback  
  to simple LIKE search. Optional businessFacts(...) (for mof/bof or intent=persuasive), and swipeStructures(...).                         
      - Selection: TemplateSelector::select(organizationId, intent, funnel_stage, platform) currently returns top Template by usage_count. 
      - Context: ContextAssembler::build([...]) enforces caps, removes raw swipe text, assembles a GenerationContext snapshot of IDs       
  (template_id, chunk_ids, fact_ids, swipe_ids, reference_ids).                                                                            
      - LLM: LLMClient::call('generate', ...) via OpenRouterService::chatJSON, returning STRICT JSON { content }. One schema-repair attempt
  if invalid.                                                                                                                              
      - Validation: PostValidator::checkPost(draft, context) checks length, emoji policy, rough sections heuristic. One repair attempt if  
  validation fails.                                                                                                                        
      - Output:                                                                                                                            
          - content: generated draft.                                                                                                      
          - context_used: snapshot of IDs used.                                                                                            
          - validation_result: boolean.                                                                                                    
          - validation: details (e.g., issues: [length_exceeded, emoji_disallowed, missing_sections]).                                     
          - metadata: { intent, funnel_stage, template_id }.                                                                               
  - Enforcement only: ContentGeneratorService::enforce(...)                                                                                
      - Applies constraints to an existing draft (length, emoji, tone) with one repair attempt.                                            
                                                                                                                                           
  AI Subsystems (Content Generation)                                                                                                       
                                                                                                                                           
  - PostClassifier:                                                                                                                        
      - Uses OpenRouterService::classify to return {intent,funnel_stage} JSON. Provides defaults if model response is missing/invalid.     
  - Retriever:                                                                                                                             
      - knowledgeChunks(orgId, userId, query, intent, limit):                                                                              
          - Vector search: embed query via EmbeddingsService::embedOne, knowledge_chunks.embedding_vec <=> query_vec, threshold            
  config('vector.similarity.threshold', 0.35), caps by intent, excerpt caps, confidence-weighted scoring.                                  
          - Fallback: keyword LIKE.                                                                                                        
      - businessFacts(orgId, userId, limit) from BusinessFact.                                                                             
      - swipeStructures(orgId, intent, platform, limit) from SwipeStructure with optional filtering.                                       
  - TemplateSelector: returns most-used Template (MVP).                                                                                    
  - ContextAssembler:                                                                                                                      
      - Caps counts and strips raw swipe text; records snapshot IDs to GenerationContext.                                                  
  - GenerationContext: read-only object carrying voice, template, chunks, facts, swipes, user_context, options, snapshot ID list.          
  - LLMClient:                                                                                                                             
      - Wraps OpenRouterService calls; logs to LlmCall with purpose, model, status, latency.                                               
  - SchemaValidator:                                                                                                                       
      - Minimal schema checks for swipe_structure, template_parse, post_generation (requires content), and sentence_rewrite.               
  - PostValidator:                                                                                                                         
      - Simple checks on length and emoji policy; rough section heuristic using template structure.                                        
                                                                                                                                           
  Data Models                                                                                                                              
                                                                                                                                           
  - GeneratedPost:                                                                                                                         
      - Fields: organization_id, user_id, platform, intent, funnel_stage, topic, template_id, request (array), context_snapshot (array),   
  content, status, validation (array).                                                                                                     
      - Status flow: queued â†’ draft or failed.                                                                                             
  - SentenceRewrite:                                                                                                                       
      - Fields: organization_id, user_id, generated_post_id (nullable), original_sentence, instruction, rewritten_sentence, meta (emoji    
  policy, max_chars, tone), created_at.                                                                                                    
  - KnowledgeItem:                                                                                                                         
      - Fields: organization_id, user_id, type, source, source_id, source_platform, title, raw_text, raw_text_sha256, metadata, confidence,
  ingested_at.                                                                                                                             
      - Relations: hasMany(KnowledgeChunk).                                                                                                
  - KnowledgeChunk:                                                                                                                        
      - Fields: knowledge_item_id, organization_id, user_id, chunk_text, chunk_type, tags (array), token_count, embedding (array),         
  embedding_model, created_at, pgvector column embedding_vec updated via job.                                                              
  - BusinessFact:                                                                                                                          
      - Fields: organization_id, user_id, type, text, confidence, source_knowledge_item_id, created_at.                                    
  - SwipeItem:                                                                                                                             
      - Fields: organization_id, user_id, platform, source_url, author_handle, raw_text, raw_text_sha256, engagement (array), saved_reason,
  created_at.                                                                                                                              
      - Relations: hasMany(SwipeStructure).                                                                                                
  - SwipeStructure:                                                                                                                        
      - Fields: swipe_item_id, intent, funnel_stage, hook_type, cta_type, structure (array), language_signals (array), confidence,         
  created_at.                                                                                                                              
  - Template:                                                                                                                              
      - Fields: organization_id, folder_id, created_by, name, description, thumbnail_url, template_type, template_data (array: { structure,
  constraints }), category, is_public, usage_count.                                                                                        
  - VoiceProfile:                                                                                                                          
      - Fields: organization_id, user_id, traits (array), confidence, sample_size, updated_at.                                             
  - LlmCall:                                                                                                                               
      - Fields: purpose, model, latency_ms, status, etc. (basic observability record).                                                     
                                                                                                                                           
  Jobs & Ingestion Pipeline                                                                                                                
                                                                                                                                           
  - BookmarkToKnowledgeItemJob:                                                                                                            
      - Turns a Bookmark into a KnowledgeItem (fetches HTML/text, dedups by SHA-256, sets low confidence).                                 
      - Chains:                                                                                                                            
          - ChunkKnowledgeItemJob â†’ creates paragraph-windowed KnowledgeChunk rows.                                                        
          - EmbedKnowledgeChunksJob â†’ calls EmbeddingsService to fill embedding_vec (pgvector) in batches.                                 
          - ExtractVoiceTraitsJob â†’ naive rolling update to VoiceProfile.                                                                  
          - ExtractBusinessFactsJob â†’ heuristic pain_point fact from first sentence.                                                       
  - ExtractSwipeStructureJob:                                                                                                              
      - Uses LLMClient with schema swipe_structure to create SwipeStructure for a SwipeItem.                                               
  - ParseTemplateFromTextJob:                                                                                                              
      - LLM-assisted parsing of example text into Template::template_data with structure and constraints.                                  
  - GeneratePostJob:                                                                                                                       
      - Loads GeneratedPost request, enriches options (reference_ids, context as user_context), calls ContentGeneratorService::generate,   
  stores content, metadata, context snapshot, validation, and sets status.                                                                 
                                                                                                                                           
  Chat + Intent Classification + DocumentUpdates                                                                                           
                                                                                                                                           
  - Intent Classification:                                                                                                                 
      - POST /api/v1/ai/classify-intent returns { read, write }, driven by AiController::buildClassificationPrompt.                        
      - Used by front end to gate actions (e.g., whether to allow write operations).                                                       
  - Chat Flow (POST /api/v1/ai/chat):                                                                                                      
      - Input may include document_context either as raw string or { document, references[] }. References with content are normalized to   
  user_context.                                                                                                                            
      - Controller sets options.retrieval_limit=3 and appends the user_context to options, then calls ContentGeneratorService::generate.   
      - Output is a DocumentUpdate:                                                                                                        
          - response: assistant explanation string.                                                                                        
          - command: { action: "replace_document", target: null, content: "<generated text>" }.                                            
  - DocumentUpdates (semantics front end applies):                                                                                         
      - replace_document: replace entire document with content.                                                                            
      - replace_section: replace a specific section named in target (e.g., a markdown heading). Not currently returned by the unified      
  generator, but supported by AiController::buildClassificationPrompt and parseResponse logic for future expansion.                        
      - insert_content: insert content at target anchor, when present.                                                                     
  - Note: Current implementation of chat uses the unified content generator and always returns a replace_document command;                 
  AiController::parseResponse supports richer JSON extraction for future edit-specific commands.                                           
                                                                                                                                           
  Frontend Usage (Hypothetical)                                                                                                            
                                                                                                                                          
  - Classify:                                                                                                                              
      - Call POST /api/v1/ai/classify-intent with the user message and current document preview to determine { read, write }.              
      - If write=false, allow chat-only guidance without applying updates.                                                                 
  - Chat with Document:                                                                                                                    
      - Build payload:                                                                                                                     
          - message: user request.                                                                                                         
          - conversation_history: last N messages (optional).                                                                              
          - document_context: either a string document or { document, references[] }. References support types:                            
            bookmark | file | snippet | url | template | swipe | fact.                                                                     
            - For knowledge (bookmark/file/snippet/url): include a small `content` string; backend uses it directly as VIP context.        
            - For template: send { type: "template", id } to override Template selection.                                                  
            - For swipe: send { type: "swipe", id } to force a swipe structure (raw swipe text is never injected).                         
            - For fact: send { type: "fact", id } to include business fact(s) by ID.                                                       
          - options: constraints (e.g., max_chars, emoji, tone).                                                                           
          - platform: target channel (e.g., twitter, linkedin).                                                                            
      - Post to POST /api/v1/ai/chat.                                                                                                      
      - Apply command:                                                                                                                     
          - If action=replace_document, set editor text to command.content.                                                                
          - If future replace_section or insert_content are returned, transform the document accordingly.                                  
      - Show response in the chat timeline for user context.                                                                               
  - Generate Post (Async):                                                                                                                 
      - POST /api/v1/ai/generate-post with prompt, platform, options, and any bookmark_ids to reference.                                   
      - Poll GET /api/v1/ai/generate-post/{id} until status is draft (then show content and validation).                                   
  - Sentence Rewrite:                                                                                                                      
      - POST /api/v1/ai/rewrite-sentence with sentence, instruction, optional rules and generated_post_id.                                 
      - Replace the sentence in the client if ok=true.                                                                                     
                                                                                                                                           
  Configuration                                                                                                                            
                                                                                                                                           
  - OpenRouter: config/services.php (services.openrouter) with api_key, api_url, chat_model, classifier_model.                             
  - Environment:                                                                                                                           
      - OPENROUTER_API_KEY, OPENROUTER_API_URL, OPENROUTER_MODEL, OPENROUTER_CLASSIFIER_MODEL.                                             
      - Optional embeddings: OPENROUTER_EMBED_MODEL.                                                                                       
      - Vector config: config/vector.php (similarity.threshold, retrieval.max_per_intent).                                                 
  - Proxies: HTTP_PROXY, HTTPS_PROXY, NO_PROXY honored by OpenRouterService.                                                               
                                                                                                                                           
  Validation, Logging, and Safety                                                                                                          
                                                                                                                                          
  - Strict request validation for all endpoints (lengths, enums, existence).                                                               
  - JSON-only LLM responses for critical paths enforced via response_format=json_object and schema checks with retry.                      
  - Logging:                                                                                                                               
      - OpenRouter diagnostics: openrouter.*.                                                                                              
      - Classification: ai.classify-intent.                                                                                                
      - Chat parse and modes: ai.chat.*.                                                                                                   
      - Retrieval analytics: retriever.semantic.                                                                                           
      - LLM calls summary: LlmCall model (purpose, model, tokens, latency, status).                                                        
  - Guardrails:                                                                                                                            
      - No raw bookmark text injection at controller-level; only IDs are persisted and resolved deeper.                                    
      - Swipe raw text is stripped from generation context.                                                                                
      - Per-intent caps for retrieval to bound prompt sizes.                                                                               

  Explicit Context Overrides (References)                                                                                                  

  - No API change: uses existing `document_context.references` in chat.                                                                    
  - Supported reference types:                                                                                                             
      - bookmark | file | snippet | url (knowledge items with `content` string)                                                            
      - template (override Template selection via `id`)                                                                                    
      - swipe (force swipe structure by `id`; raw swipe text never injected)                                                               
      - fact (include Business Fact by `id`)                                                                                                
  - Behavior:                                                                                                                              
      - VIP-first injection: referenced items are inserted into context before automatic retrieval and deducted from the token budget.      
      - Automatic backfill: if budget remains, normal retrieval adds chunks/facts based on scoring.                                        
      - Knowledge references with `content` are used directly; avoids DB lookups for the text.                                             
      - Traceability: all `reference_ids` are recorded in the snapshot for replay/debug.                                                   
  - Example payload:                                                                                                                       
      {                                                                                                                                     
        "message": "@MyTemplate write a listicle using this note",                                                                       
        "document_context": {                                                                                                             
          "document": "# Current draft...",                                                                                              
          "references": [                                                                                                                 
            { "type": "template", "id": "tpl_123", "title": "Listicle Structure" },                                                
            { "type": "bookmark", "id": "019b3...", "title": "Key ideas", "content": "Short snippet to force into context" },     
            { "type": "swipe", "id": "swp_456" },                                                                                    
            { "type": "fact", "id": "bf_789" }                                                                                       
          ]                                                                                                                                
        },                                                                                                                                 
        "options": { "max_chars": 280, "emoji": "disallow", "tone": "direct" },                                                     
        "platform": "twitter"                                                                                                            
      }                                                                                                                                     

  Token-Budget Pruning                                                                                                                     
                                                                                                                                          
  - `ContextAssembler` limits content using a token budget (default 1800) with density-aware ranking.                                      
  - Tracks usage by category: chunks, facts, user_context, template, swipes, total.                                                        
  - Exposed in replay output: `context.token_usage` and counts of provided/used/pruned items.                                              
  - Configure: `config/prompting.php` â†’ `context_token_budget` or env `PROMPT_CONTEXT_TOKEN_BUDGET`.                                       

  Swipe Structure Similarity                                                                                                               
                                                                                                                                          
  - `Retriever::swipeStructures(...)` ranks candidates by structural overlap with the selected template.                                    
  - Uses Jaccard similarity over section names with a small order-alignment bonus.                                                         
  - Ensures pacing/flow (â€œHook â†’ Conflict â†’ Resolutionâ€) matches the requested intent/platform.                                            

  Quality Evaluation Loop                                                                                                                  
                                                                                                                                          
  - `PostQualityEvaluator` computes: relevance, structure_adherence, readability, length_fit, emoji_compliance.                            
  - Weighted blend yields `overall_score` stored in `generation_quality_reports`.                                                          
  - Query example: `SELECT AVG(overall_score) FROM generation_quality_reports WHERE intent = 'persuasive';`                                

  Replayable Debugging                                                                                                                     
                                                                                                                                          
  - `generation_snapshots` stores prompt, classification, template data, chunks/facts (id+text), swipes, user_context, options, and output.
  - Replay endpoint and CLI regenerate with overrides and emit:                                                                            
      - model_used, processing_time, token_usage, raw/system prompt, validation metrics, quality scores.                                   

  End-to-End Content Generation Summary                                                                                                    
                                                                                                                                           
  - Input: prompt + constraints (+ implicit org/user context + references).                                                                
  - Classify: intent/funnel to drive template and retrieval policy.                                                                        
  - Retrieve: semantic knowledge chunks, business facts, swipe structures.                                                                 
  - Select: template and voice profile for the org/user.                                                                                   
  - Assemble: constrained and sanitized GenerationContext with snapshot IDs.                                                               
  - Generate: strict JSON {content} via LLM, with schema enforcement.                                                                      
  - Validate & Repair: enforce max_chars, emoji policy, and rough structure; one repair cycle. Emits validation metrics.                   
  - Persist (async job): content, validation, template ID, and context snapshot on the GeneratedPost.                                      

  Data Models                                                                                                                              
                                                                                                                                          
  - GenerationSnapshot (app/Models/GenerationSnapshot.php): replayable state for a generation.                                            
  - GenerationQualityReport (app/Models/GenerationQualityReport.php): per-output QA scores.                                                
  - LlmCall updated to include input/output tokens when available.                                                                         

  Configuration                                                                                                                            
                                                                                                                                          
  - OpenRouter: config/services.php (services.openrouter) with api_key, api_url, chat_model, classifier_model.                             
  - Prompting: config/prompting.php â†’ `context_token_budget` (env PROMPT_CONTEXT_TOKEN_BUDGET).                                            
  - Vector: config/vector.php (similarity.threshold, retrieval.max_per_intent).                                                            
  - Proxies: HTTP_PROXY, HTTPS_PROXY, NO_PROXY honored by OpenRouterService.                                                               

  CLI                                                                                                                                        
                                                                                                                                          
  - `php artisan ai:list-snapshots [--org=] [--intent=] [--limit=20] [--json]`                                                             
  - `php artisan ai:replay-snapshot {snapshot_id} [--platform=] [--max-chars=] [--emoji=] [--tone=] [--budget=] [--no-report]`            
  - `php artisan ai:show-prompt {snapshot_id}`                                                                                             
                                                                                                                                           
  If you want, I can add a short README under docs/ consolidating this documentation or sketch a frontend utility to apply DocumentUpdates 
  for replace_section and insert_content in a markdown editor.                                                                             

  Improvements Update                                                                                                                     
                                                                                                                                          
  - Expanded Options Contract                                                                                                             
      - intent: educational|persuasive|emotional|story|contrarian                                                                          
      - funnel_stage: tof|mof|bof (skips/augments classifier per overrides)                                                                
      - voice_profile_id, voice_inline: choose explicit profile or inline traits, else org default                                         
      - use_retrieval (bool), retrieval_limit (int)                                                                                        
      - use_business_facts (bool; defaults true for persuasive or mof/bof)                                                                 
      - swipe_mode: auto|none|strict and swipe_ids: []                                                                                     
      - template_id: force a template                                                                                                      
      - context_token_budget: expose pruning budget                                                                                        
      - business_context: VIP string that is never pruned and logged                                                                       

  - Deterministic Swipe Selection                                                                                                         
      - No embeddings: symbolic scoring from structure sections                                                                            
      - Base: Jaccard similarity + small prefix-order bonus                                                                                
      - Bonuses: +hook and +cta alignment; +intent and +funnel matches                                                                     
      - Confidence-weighted: final_score = (similarity+bonuses) * confidence                                                               
      - Select: sort desc, take top N (config swipe.top_n, default 2), discard below threshold (config swipe.similarity_threshold, 0.30). 
      - If none pass threshold: proceed template-only (no random fallback)                                                                 

  - Context & Prompting                                                                                                                   
      - ContextAssembler: reports per-category token usage and prunes to `context_token_budget`                                            
      - business_context is always included and never pruned (counts tokens separately)                                                    
      - System prompt enforces: Template is authoritative; swipes influence style/rhythm only                                              

  - Snapshot Diagnostics                                                                                                                  
      - options now include:                                                                                                               
          - classification_overridden + classification_original (if applicable)                                                            
          - voice_source (profile|inline|org_default|none)                                                                                 
          - retrieval_enabled, business_facts_enabled, business_context                                                                     
          - swipe_mode, swipe_ids, swipe_scores, swipe_rejected                                                                             
          - token_usage per category (chunks, facts, user, business_context, template, swipes, total)                                      

  - Configuration                                                                                                                         
      - New: config/swipe.php with:                                                                                                        
          - similarity_threshold (default 0.30)                                                                                             
          - top_n (default 2)                                                                                                               
