Supreme leader,

## What’s wrong with this service (so you know what to target)

* **God object / orchestration + business logic + persistence + prompt building + error handling** all in one method (`generate()`).
* **Options parsing is ad-hoc** (strings/ints/bools everywhere, mixed defaults, mutated `$options` mid-flight).
* **Override/VIP logic is tangled** and repeats queries (template override occurs twice, swipe merging rules are buried).
* **Context is built twice but not actually used for generation**: you build `$context = $assembler->build(...)` then you manually re-assemble `$user` prompt from raw arrays (`$chunks`, `$facts`, etc.). That guarantees drift (and explains “snapshot user_context differs from LLM user_context” class of bugs).
* **LLM call contract repeated** (generate vs replay vs enforce) with slightly different rules and logging.
* **Persistence/metrics side effects** (snapshot store + quality eval) live inside generation path.
* **Silent exception swallowing** everywhere (`catch (\Throwable) {}`) makes debugging painful.

The refactor plan below is designed to fix those without rewriting the entire system.

---

## Refactoring plan (incremental, safe)

### Phase 0 — Put a hard boundary around inputs (1–2 files, low risk)

**Goal:** one canonical “request” object so defaults/typing are centralized.

1. Create `GenerationRequest` DTO (or value object) that:

* Accepts `orgId, userId, prompt, platform, options`
* Normalizes types (bool/int/string) once
* Exposes structured sub-objects:

  * `constraints` (maxChars, emojiPolicy, tone)
  * `classificationOverrides` (intent?, funnelStage?)
  * `retrievalPolicy` (useRetrieval, retrievalLimit, useBusinessFacts)
  * `voiceOverrides` (voiceProfileId?, voiceInline?)
  * `swipePolicy` (mode, swipeIds)
  * `templatePolicy` (templateId override)
  * `contextInputs` (userContext, businessContext, referenceIds)
  * `vipOverrides` (knowledge[], facts[], swipes[], templateId?)
* Keeps a copy of **raw options** for snapshotting, but never mutates them mid-flow.

**Net effect:** `generate()` stops being 300 lines of parsing.

---

### Phase 1 — Extract “VIP overrides resolution” (big win for clarity)

**Goal:** no DB queries and no try/catch soup inside `generate()`.

Create `OverrideResolver` with:

* `resolveVipKnowledge($overrides): VipKnowledgeResult`
* `resolveVipFacts($orgId, $factIds): VipFactsResult`
* `resolveVipSwipes($orgId, $swipeIds): VipSwipesResult`
* `resolveTemplateOverride($orgId, $templateId): ?Template`

It returns:

* `vipChunks, vipFacts, vipSwipes, overrideTemplate, referenceIdsAdded`

**Rules become explicit** and unit-testable:

* Overrides never pruned.
* In strict swipe mode, overrides become the list.
* Duplicate `referenceIds` are de-duped here (do it once).

---

### Phase 2 — Make “context” the single source of truth (fixes your discrepancy bugs)

**Goal:** what you persist as snapshot == what you send to the LLM.

Right now:

* Snapshot context comes from `$assembler->build(...)`
* LLM prompt comes from manual `$user .= ... json_encode($chunks)` etc.

That must die.

Create `PromptComposer` that takes the **built `Context` object** and produces:

* `system`
* `user`
* `schemaName`
* `llmParams`

Example interface:

* `composePostGeneration(Context $context, Constraints $c, string $prompt): Prompt`
* `composeRepair(Context $context, Constraints $c, string $draft, array $issues): Prompt`

Then **in generate()**:

* Build context once.
* Compose prompts from context only.
* Snapshot uses the same context object.

This is the single highest-value refactor because it removes “two sources of truth” drift.

---

### Phase 3 — Extract “pipeline steps” into a small orchestrator (clean separation)

**Goal:** `ContentGeneratorService` becomes an orchestrator that reads like a checklist.

Create these small services:

1. `ClassificationService`

* `classify(string $prompt, ?string $intentOverride, ?string $funnelOverride): ClassificationResult`
* Returns:

  * `final` (intent/funnel)
  * `original` (nullable)
  * `overridden` bool

2. `RetrievalService`

* `retrieve(ContextPlan $plan): RetrievalResult`
* Returns `chunks, facts, swipesBundle, swipeDiagnostics`

3. `TemplateService`

* `previewTemplate(...)` (current selector logic)
* `applyOverrides(templatePreview, overrideTemplate, templateOverrideOpt)`

4. `VoiceResolver`

* `resolve($orgId, voiceProfileId?, voiceInline?): VoiceResolution`
* Returns `voice`, `source`, `voiceProfileUsedId`

5. `BusinessProfileResolver`

* `resolveForOrg($orgId, $incomingBusinessContext, $options): BusinessProfileResolution`
* Returns `businessContext`, `bpSnapshot`, `emojiPolicyDerived?`, and `used/version/retrieval_level`
* Crucially: it does **not** mutate `$options`. It returns derived constraints explicitly.

6. `GenerationRunner`

* Handles `llm->call`, schema validate, retry-on-schema-failure, logging.
* One function:

  * `runJsonContentCall(callName, Prompt $prompt): LlmJsonContentResult`
* Used by generate, repair, replay, enforce.

7. `ValidationAndRepairService`

* `validateAndRepair(draft, context, constraints): ValidationRepairResult`
* Encapsulates:

  * validator check
  * emoji local sanitize fast-path
  * one LLM repair attempt
  * schema validation + retry
  * fallback behavior when repair returns empty

8. `SnapshotPersister` (side effects isolated)

* `persistGeneration(snapshot inputs...)`
* `persistQuality(...)`

Now `generate()` becomes ~40–70 lines.

---

### Phase 4 — Unify `generate()`, `enforce()`, `replayFromSnapshot()` around shared components

**Goal:** eliminate duplicated LLM + schema + logging logic.

* `enforce()` should reuse:

  * `ContextAssembler` (ok)
  * `PromptComposer::composeRepair(...)`
  * `GenerationRunner`
  * `ValidationAndRepairService` (or a slim “repair-only” method)

* `replayFromSnapshot()` should reuse:

  * `ContextFactory::fromSnapshot($snap, $overrides)` (new)
  * `PromptComposer` (from context)
  * `GenerationRunner`
  * `Validator + QualityEvaluator`

After this, only differences are inputs and whether to persist reports.

---

### Phase 5 — Make rules explicit as a “policy” object (prevents regression)

**Goal:** stop accidental changes to precedence logic.

Create `GenerationPolicy` (pure config / pure logic), codifying:

* Template precedence: overrides.template_id > options.template_id > selector
* Swipe precedence per mode (none/strict/auto) + VIP insertion rules
* Business facts default rule by intent/funnel
* Emoji policy precedence: explicit option > business profile tone signature > default
* Voice precedence: voice_profile_id > voice_inline > none

Use it in services. Unit-test it.

---

## Concrete file/module layout (suggested)

```
App/Services/Ai/ContentGeneratorService.php        // orchestrator only
App/Services/Ai/Generation/
  DTO/GenerationRequest.php
  DTO/Constraints.php
  DTO/ClassificationResult.php
  DTO/VoiceResolution.php
  DTO/BusinessProfileResolution.php
  DTO/Prompt.php
  Policy/GenerationPolicy.php
  Steps/
    ClassificationService.php
    BusinessProfileResolver.php
    RetrievalService.php
    TemplateService.php
    VoiceResolver.php
    PromptComposer.php
    GenerationRunner.php
    ValidationAndRepairService.php
    SnapshotPersister.php
  Factories/
    ContextFactory.php          // from request or from snapshot
    TemplateFactory.php         // from id / snapshot
```

---

## Specific “must fix” changes for debuggability

1. **Stop swallowing exceptions silently.**

   * At minimum: `Log::warning('...', ['error' => $e->getMessage()])`.
   * Or return an `errors[]` array inside debug metadata.

2. **De-dupe reference IDs once**, early:

   * `array_values(array_unique(array_filter(...)))`

3. **Add a “run_id” correlation ID** that is included in all logs + snapshot options.

   * Makes tracing one generation across logs trivial.

4. **Replace `stripEmojis()` regex** with a centralized `EmojiSanitizer` used by validator and generator so the definition matches. (Right now, your validator likely uses different ranges/logic, which is why “emoji true” can still trigger validation errors.)

5. **Stop building prompts from raw arrays**. Only from `Context` (Phase 2).

---

## Minimal step order (if you want the fastest payoff)

If you only do 4 steps, do these in order:

1. **PromptComposer from Context** (eliminates context drift immediately).
2. **GenerationRunner** (dedupe schema validate + retry + logging).
3. **ValidationAndRepairService** (dedupe repair path; fix empty-content handling consistently).
4. **GenerationRequest DTO + Policy** (removes the parsing chaos).

---

## What success looks like (acceptance criteria)

* For any generation, you can print a single object (`GenerationTrace`) containing:

  * normalized request
  * resolved policy decisions (template source, swipe mode result, emoji policy source)
  * context counts + token usage
  * exact system/user strings sent to LLM
  * schema validation result(s)
  * validation issues and repair steps taken
  * snapshot id and run_id
* `generate()` is readable and under ~100 lines.
* Replay/enforce share the same LLM/schema/repair mechanics.
* No more “snapshot user_context differs from what was sent.”

If you want, paste your `ContextAssembler` and `PostValidator` signatures; the plan above will get sharper around what should live inside `Context` vs what belongs in `PromptComposer`.
