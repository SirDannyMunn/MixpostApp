# Leads Page Implementation Plan

> Maps each missing PRD feature to existing backend/frontend infrastructure and identifies what must be built new.

---

## Quick Reference: Existing Infrastructure

| Layer | Package / Path | Key Assets |
|-------|---------------|------------|
| **Backend: Lead Intelligence** | `packages/lead-intelligence` | Lead model, LeadController (index/show/updateStatus/provenance), LeadExportController (csv/webhook), LeadResource, enums (LeadStatus, SignalType, InteractionType) |
| **Backend: Lead Scoring** | `packages/lead-scoring` | LeadScore model (icp_fit_score, intent_score, overall_score), IntentSignal model, IcpProfile model + controller, LeadScorer service |
| **Backend: Lead Watcher** | `packages/lead-watcher` | Lead model (with list_id FK), LeadList model + ListController (CRUD), LeadController (store only) |
| **Backend: Lead Outreach** | `packages/lead-outreach-orchestrator` | Campaign, CampaignContact, CampaignStep models + CampaignController (full CRUD, contacts add/remove) |
| **Frontend: API** | `engage-new/src/lib/lead-watcher-api.ts` | `leads.list()`, `leads.get()`, `leads.updateStatus()`, `leads.getInteractions()`, `leads.getIntentSignals()`, `exports.csv()`, lists, agents, ICP hooks |
| **Frontend: Contact API** | `engage-new/src/lib/contact-api.ts` | `enrichContactEmail()`, `enrichContactEmailsBatch()` — targets `/contacts/*` routes (non-existent) |
| **Frontend: Hooks** | `engage-new/src/lib/hooks.ts` | `useLeads()`, `useLead()`, `useUpdateLeadStatus()`, `useLeadInteractions()`, `useLeadIntentSignals()` |
| **Frontend: Store** | `engage-new/src/lib/lead-store.ts` | Zustand store: fetchLeads, setFilters, setPage |
| **Frontend: Types** | `engage-new/src/types/` | LeadSummary, LeadDetailResponse, ContactScoring (AIScoreLevel, FitScore), LeadsListParams |
| **Frontend: Components** | `engage-new/src/components/leads/` | LeadsPage, LeadsListPage, LeadDetailSidebar |

### Database Tables (Existing)

| Table | Key Columns |
|-------|------------|
| `lw_leads` | id, organization_id, primary_platform, profile_url, display_name, headline, bio, location, company_name, company_id, status, first_seen_at, last_seen_at, list_id, metadata |
| `lw_companies` | id, name, domain, industry, headcount_range, metadata |
| `lw_lists` | id, organization_id, name, description, color, is_archived, metadata |
| `lw_intent_signals` | id, organization_id, lead_id, signal_type, source_type, occurred_at, strength_score, confidence_score, explanation, sw_content_node_id |
| `lw_interactions` | id, organization_id, actor_lead_id, interaction_type, target_type, target_id, occurred_at, weight |
| `lw_lead_scores` | id, lead_id, icp_profile_id, icp_fit_score, intent_score, overall_score, score_breakdown, computed_at |
| `lw_lead_organization` | lead_id, organization_id, status, added_at, added_by_user_id, metadata |
| `lw_campaigns` | id, organization_id, name, status, total_contacts, etc. |
| `lw_campaign_contacts` | id, campaign_id, lead_id, status, current_step_id, invitation_sent, has_replied, etc. |
| `lw_campaign_steps` | id, campaign_id, order, type, message_template, delay_days, etc. |

---

## Feature-by-Feature Implementation Plan

---

### 1. Lead Interactions Endpoint

**PRD Ref:** §5.1 — `GET /leads/{id}/interactions`

**Existing:**
- Route already registered in `lead-intelligence/routes/api.php`
- `Interaction` model exists with relationships, scopes (`forOrganization`, `forActor`, `forTargetType`, `forContentNode`, `recent`)
- `Lead.interactions` HasMany relationship defined (via `actor_lead_id`)
- Frontend hook `useLeadInteractions()` and API method `leads.getInteractions()` already exist
- `lw_interactions` table has all needed columns

**What's Missing:**
- Controller method body — currently routed to `interactions()` but the method is **not implemented** in `LeadController`

**Implementation:**
1. **Backend (lead-intelligence):** Add `interactions()` method to `LeadController.php`:
   ```php
   public function interactions(Request $request, string $id): JsonResponse
   {
       $lead = Lead::findOrFail($id);
       $limit = $request->integer('limit', 20);
       
       $interactions = $lead->interactions()
           ->latest('occurred_at')
           ->limit($limit)
           ->get()
           ->map(fn($i) => [
               'id' => $i->id,
               'interaction_type' => $i->interaction_type->value,
               'label' => $i->interaction_type->label(),
               'target_type' => $i->target_type,
               'occurred_at' => $i->occurred_at->toIso8601String(),
               'weight' => $i->weight,
               'sw_content_node_id' => $i->sw_content_node_id,
           ]);
       
       return response()->json(['data' => $interactions]);
   }
   ```
2. **Frontend:** Already wired — `useLeadInteractions(id)` calls the endpoint. No changes needed.

**Effort:** Small — 1 controller method.

---

### 2. Lead Intent Signals Endpoint

**PRD Ref:** §5.1 — `GET /leads/{id}/intent-signals`

**Existing:**
- Route already registered in `lead-intelligence/routes/api.php`
- `IntentSignal` model exists with scopes (`forLead`, `byType`, `recent`, `strong`)
- `Lead.intentSignals` HasMany relationship defined
- Frontend hook `useLeadIntentSignals()` and API method `leads.getIntentSignals()` already exist
- `lw_intent_signals` table has all needed columns

**What's Missing:**
- Controller method body — routed but **not implemented** in `LeadController`

**Implementation:**
1. **Backend (lead-intelligence):** Add `intentSignals()` method to `LeadController.php`:
   ```php
   public function intentSignals(Request $request, string $id): JsonResponse
   {
       $lead = Lead::findOrFail($id);
       $limit = $request->integer('limit', 20);
       
       $signals = $lead->intentSignals()
           ->latest('occurred_at')
           ->limit($limit)
           ->get()
           ->map(fn($s) => [
               'id' => $s->id,
               'signal_type' => $s->signal_type->value,
               'label' => $s->signal_type->label(),
               'icon' => $s->signal_type->icon(),
               'source_type' => $s->source_type,
               'occurred_at' => $s->occurred_at->toIso8601String(),
               'strength_score' => $s->strength_score,
               'confidence_score' => $s->confidence_score,
               'explanation' => $s->explanation,
               'sw_content_node_id' => $s->sw_content_node_id,
           ]);
       
       return response()->json(['data' => $signals]);
   }
   ```
2. **Frontend:** Already wired. No changes needed.

**Effort:** Small — 1 controller method.

---

### 3. Lead Qualification / Fit Status

**PRD Ref:** §5.2 — `POST /leads/{id}/fit-status`

**Existing:**
- `LeadScore.icp_fit_score` is a computed 0.0–1.0 float from the scorer service — this is NOT the same as user-assigned fit qualification
- Frontend `contact-types.ts` defines `FitScore` enum (`yes`, `partly`, `no`, `not_defined`) — matches the concept
- `lw_lead_organization` pivot has `metadata` JSON column that could store fit_status per org

**What's Missing:**
- No `fit_status` column on `lw_leads` or `lw_lead_organization`
- No endpoint to update fit status
- No frontend toggle wired to backend

**Decision: Where to store fit_status?**
- **Option A (Recommended):** Add `fit_status` column to `lw_leads` table — simpler, works because leads are already org-scoped
- **Option B:** Store in `lw_lead_organization.metadata` JSON — more flexible for multi-org scenarios but harder to query/filter

**Implementation:**
1. **Migration:** Add `fit_status` column to `lw_leads`:
   ```php
   $table->string('fit_status')->default('unknown')->after('status');
   // Values: qualified, unknown, out_of_scope
   ```
2. **Backend Enum:** Create `FitStatus` enum in `lead-intelligence/src/Enums/`:
   ```php
   enum FitStatus: string {
       case QUALIFIED = 'qualified';
       case UNKNOWN = 'unknown';
       case OUT_OF_SCOPE = 'out_of_scope';
   }
   ```
3. **Backend Endpoint:** Add `updateFitStatus()` method to `LeadController`:
   ```php
   public function updateFitStatus(Request $request, string $id): JsonResponse
   {
       $validated = $request->validate([
           'fit_status' => ['required', Rule::in(['qualified', 'unknown', 'out_of_scope'])],
       ]);
       $lead = Lead::findOrFail($id);
       $lead->update($validated);
       return response()->json(['data' => ['id' => $lead->id, 'fit_status' => $lead->fit_status]]);
   }
   ```
4. **Route:** `POST /leads/{id}/fit-status` → `LeadController@updateFitStatus`
5. **Frontend API:** Add `leads.updateFitStatus(id, status)` to `lead-watcher-api.ts`
6. **Frontend Hook:** Add `useUpdateLeadFitStatus()` mutation hook
7. **Frontend UI:** Add fit column to table + qualification buttons in sidebar footer

**Effort:** Medium — migration, enum, endpoint, frontend toggle.

---

### 4. Lead List Assignments

**PRD Ref:** §5.3 — Add/remove leads from lists

**Existing:**
- `LeadList` model with CRUD via `ListController` (in `lead-watcher` package)
- `Lead` has `list_id` FK → single list assignment (1:many)
- Frontend `leadWatcherApi.lists.*` CRUD methods exist
- Frontend `useLeadLists()` hook exists

**Decision: Single list vs. multi-list?**
- Current schema: `list_id` FK on `lw_leads` = **one list per lead**
- PRD implies single list assignment (dropdown, not multi-select)
- **Recommendation:** Keep `list_id` FK approach. No pivot table needed.

**What's Missing:**
- No endpoint to batch-assign leads to a list
- No endpoint to remove leads from a list
- `LeadController.index()` (lead-intelligence package) doesn't filter by `list_id`

**Implementation:**
1. **Backend Endpoint (lead-watcher):** Add batch assign/remove to `ListController` or a new endpoint:
   ```php
   // POST /lists/{id}/leads — assign leads to list
   public function addLeads(Request $request, string $id): JsonResponse
   {
       $list = LeadList::findOrFail($id);
       $validated = $request->validate(['lead_ids' => 'required|array', 'lead_ids.*' => 'uuid']);
       $count = Lead::whereIn('id', $validated['lead_ids'])->update(['list_id' => $list->id]);
       return response()->json(['data' => ['updated' => $count]]);
   }
   
   // DELETE /lists/{id}/leads — remove leads from list
   public function removeLeads(Request $request, string $id): JsonResponse
   {
       $validated = $request->validate(['lead_ids' => 'required|array', 'lead_ids.*' => 'uuid']);
       $count = Lead::where('list_id', $id)->whereIn('id', $validated['lead_ids'])->update(['list_id' => null]);
       return response()->json(['data' => ['updated' => $count]]);
   }
   ```
2. **Backend Filter:** Add `list_id` filter to `LeadController.index()` in lead-intelligence:
   ```php
   if ($request->filled('list_id')) {
       $query->where('list_id', $request->query('list_id'));
   }
   ```
3. **Frontend API:** Add `lists.addLeads(listId, leadIds)` and `lists.removeLeads(listId, leadIds)` to `lead-watcher-api.ts`
4. **Frontend UI:** Add list column to table, list picker in bulk actions, list display in sidebar
5. **Include list data in lead index/show responses:** Eager load `list:id,name,color` on the Lead query and include in response

**Effort:** Medium — endpoints, filter, frontend picker.

---

### 5. Lead Enrichment (Email)

**PRD Ref:** §5.4 — Enrich email for leads

**Existing:**
- Frontend `contact-api.ts` has `enrichContactEmail(contactId)` and `enrichContactEmailsBatch(contactIds)` — but these target **`/contacts/*` routes that don't exist** in backend
- No enrichment backend endpoint exists under `/leads/*`

**What's Missing:**
- Backend enrichment endpoint
- Integration with an enrichment service (e.g., ProspectLinker, Pipl, Hunter.io)
- Frontend needs to call leads-prefixed endpoint instead of contacts

**Implementation:**
1. **Backend Endpoints (lead-intelligence):** Create `LeadEnrichmentController`:
   - `POST /leads/{id}/enrich-email` — single lead enrichment
   - `POST /leads/enrich-email/batch` — batch enrichment (up to 500 IDs)
   - These dispatch async jobs; return `{ status: 'queued' }` immediately
   - When enrichment completes, update `lead.metadata.email` or add an `email` column
2. **Migration:** Add `email` column to `lw_leads` (currently email is only in frontend mock data, not in schema):
   ```php
   $table->string('email')->nullable()->after('company_name');
   ```
3. **Frontend API:** Add `leads.enrichEmail(id)` and `leads.enrichEmailBatch(ids)` to `lead-watcher-api.ts`
4. **Frontend:** Replace `contact-api.ts` enrichment calls with new leads-based methods
5. **Frontend UI:** "Find Email" button in sidebar header, "Enrich Email" in bulk actions

**Effort:** Large — new controller, job/queue, enrichment service integration, migration.

**Note:** The actual enrichment service integration depends on which third-party provider is used. The endpoints and job structure can be built now; the service adapter is a separate concern.

---

### 6. Export (CSV)

**PRD Ref:** §5.5 — Export leads to CSV

**Existing:**
- `LeadExportController.csv()` already works — streams CSV with columns: linkedin_url, name, headline, company, location, score, status, top_signals, evidence_count, first/last_seen_at
- Route: `GET /exports/csv` with filters (min_score, status, since, signals, limit)
- Frontend `exports.csv()` API method exists in `lead-watcher-api.ts`
- Frontend `useExportLeadsCsv()` hook exists

**What's Missing:**
- No support for exporting **specific lead IDs** (selection-based export)
- CSV doesn't include email, fit_status, or list name
- Frontend bulk export action not wired

**Implementation:**
1. **Backend:** Extend `LeadExportController.csv()` to accept `lead_ids[]` parameter:
   ```php
   if (!empty($validated['lead_ids'])) {
       $query->whereIn('id', $validated['lead_ids']);
   }
   ```
   Add email, fit_status, list columns to CSV output.
2. **Frontend:** Wire bulk "Export" action to call `exports.csv()` with selected lead IDs
3. **Validation:** Add `'lead_ids' => ['nullable', 'array'], 'lead_ids.*' => 'uuid'` to existing validation rules

**Effort:** Small — extend existing endpoint + wire frontend.

---

### 7. Campaign Linkage

**PRD Ref:** §5.6 — Lead-to-campaign association

**Existing:**
- `CampaignContact` model exists — links `campaign_id` to `lead_id` via `lw_campaign_contacts` table
- `CampaignController` has `addContacts()` and `removeContacts()` methods on campaign routes
- Routes: `POST /campaigns/{id}/contacts`, `DELETE /campaigns/{id}/contacts`
- `Campaign.contacts` relationship loads campaign contacts
- `CampaignStep` model provides step sequence for journey visualization

**What's Missing:**
- No **lead-centric** campaign endpoint (`GET /leads/{id}/campaigns`)
- Frontend sidebar has no campaign section
- No journey step visualization component

**Implementation:**
1. **Backend Endpoint (lead-intelligence):** Add `campaigns()` method to `LeadController`:
   ```php
   public function campaigns(Request $request, string $id): JsonResponse
   {
       $lead = Lead::findOrFail($id);
       $contacts = CampaignContact::where('lead_id', $id)
           ->with(['campaign:id,name,status', 'currentStep:id,order,type'])
           ->get()
           ->map(fn($cc) => [
               'campaign_id' => $cc->campaign_id,
               'campaign_name' => $cc->campaign->name,
               'campaign_status' => $cc->campaign->status,
               'contact_status' => $cc->status,
               'current_step_order' => $cc->current_step_order,
               'enrolled_at' => $cc->enrolled_at?->toIso8601String(),
               'has_replied' => $cc->has_replied,
           ]);
       return response()->json(['data' => $contacts]);
   }
   ```
2. **Route:** `GET /leads/{id}/campaigns` → `LeadController@campaigns`
3. **Backend:** Also add `POST /leads/{id}/campaigns` (assign) and `DELETE /leads/{id}/campaigns/{campaignId}` (remove) — these delegate to existing CampaignContact create/delete logic
4. **Frontend API:** Add `leads.getCampaigns(id)`, `leads.addToCampaign(id, campaignId)`, `leads.removeFromCampaign(id, campaignId)` to `lead-watcher-api.ts`
5. **Frontend Hook:** Add `useLeadCampaigns(id)` query hook
6. **Frontend UI:** Build campaign section in LeadDetailSidebar with step journey visualization

**Effort:** Medium — endpoint reuses existing models, frontend component is new.

---

### 8. Internal Notes

**PRD Ref:** §5.7 — Notes per lead

**Existing:**
- `lw_leads.metadata` JSON column could store notes
- `lw_lead_organization.metadata` JSON could also store per-org notes
- No dedicated notes column or endpoint

**Decision:**
- **Recommendation:** Add `internal_notes` text column to `lw_leads` — simpler than JSON, supports full-text search later

**Implementation:**
1. **Migration:** Add `internal_notes` column:
   ```php
   $table->text('internal_notes')->nullable()->after('metadata');
   ```
2. **Backend Endpoint:** Add `updateNotes()` to `LeadController`:
   ```php
   public function updateNotes(Request $request, string $id): JsonResponse
   {
       $validated = $request->validate(['internal_notes' => 'nullable|string|max:10000']);
       $lead = Lead::findOrFail($id);
       $lead->update($validated);
       return response()->json(['data' => ['id' => $lead->id, 'internal_notes' => $lead->internal_notes]]);
   }
   ```
3. **Route:** `PATCH /leads/{id}/notes` → `LeadController@updateNotes`
4. **Frontend API:** Add `leads.updateNotes(id, notes)` 
5. **Frontend UI:** Notes textarea in sidebar with save button
6. **Include in show response:** Return `internal_notes` in `LeadController.show()`

**Effort:** Small — migration + simple CRUD.

---

### 9. Activity Logs

**PRD Ref:** §5.7 — Activity log per lead

**Existing:**
- No activity log table or model exists
- `lw_interactions` tracks social interactions, not system activity
- `lw_intent_signals` tracks signals, not user actions

**What's Missing:**
- Everything — table, model, logging hooks, endpoint, frontend

**Implementation:**
1. **Migration:** Create `lw_lead_activity_logs` table:
   ```php
   Schema::create('lw_lead_activity_logs', function (Blueprint $table) {
       $table->uuid('id')->primary();
       $table->uuid('lead_id')->index();
       $table->uuid('organization_id')->index();
       $table->string('type'); // status_changed, fit_updated, note_added, enrichment_completed, campaign_added, exported, etc.
       $table->text('message');
       $table->uuid('actor_id')->nullable(); // user who performed action
       $table->json('metadata')->nullable(); // old/new values
       $table->timestamps();
       $table->foreign('lead_id')->references('id')->on('lw_leads')->cascadeOnDelete();
       $table->index(['lead_id', 'created_at']);
   });
   ```
2. **Model:** Create `LeadActivityLog` in `lead-intelligence`
3. **Service:** Create `LeadActivityLogger` service with helper methods:
   ```php
   LeadActivityLogger::log($leadId, $orgId, 'status_changed', "Status changed from new to reviewing", $userId, ['old' => 'new', 'new' => 'reviewing']);
   ```
4. **Hooks:** Call logger from existing mutation methods:
   - `updateStatus()` → log status change
   - `updateFitStatus()` → log fit change
   - `updateNotes()` → log note update
   - Campaign add/remove → log campaign events
   - Enrichment → log enrichment result
5. **Backend Endpoint:** Add `activityLogs()` to `LeadController`:
   - `GET /leads/{id}/activity-logs` with pagination
6. **Frontend API:** Add `leads.getActivityLogs(id)` 
7. **Frontend Hook:** Add `useLeadActivityLogs(id)` 
8. **Frontend UI:** Activity log section in sidebar with empty state

**Effort:** Large — new table, model, service, logging integration, endpoint, frontend.

---

### 10. Lead Delete

**PRD Ref:** §5.8 — `DELETE /leads/{id}`

**Existing:**
- `lw_leads` table does NOT have `deleted_at` / SoftDeletes
- Lead model does NOT use SoftDeletes trait
- Cascade deletes exist on `lw_intent_signals`, `lw_interactions`, `lw_campaign_contacts` FKs 
- `lw_lead_organization` pivot also cascades on lead delete
- No delete endpoint exists

**Decision:**
- **Recommendation:** Use hard delete — cascading FKs handle cleanup. If soft delete is preferred, add migration + trait.

**Implementation:**
1. **Backend Endpoint:** Add `destroy()` to `LeadController`:
   ```php
   public function destroy(Request $request, string $id): JsonResponse
   {
       $lead = Lead::findOrFail($id);
       // Optional: authorization check
       $lead->delete();
       return response()->json(null, 204);
   }
   ```
2. **Route:** `DELETE /leads/{id}` → `LeadController@destroy`
3. **Frontend API:** Add `leads.delete(id)` to `lead-watcher-api.ts`
4. **Frontend Hook:** Add `useDeleteLead()` mutation that invalidates lead list cache
5. **Frontend UI:** Delete button in sidebar footer with confirmation dialog

**Effort:** Small — endpoint + confirmation UI.

---

### 11. Top Navigation & Filters (Frontend)

**PRD Ref:** §4.1

**Existing:**
- `LeadsListPage` has search input wired to `q` param
- `useLeads()` hook accepts `LeadsListParams` with: `status`, `min_score`, `signal_type`, `icp_profile_id`, `date_from`, `date_to`, `q`, `sort`, `direction`, `per_page`, `page`
- `lead-store.ts` has `setFilters()` to update filter state
- Backend `LeadController.index()` supports all these filter params

**What's Missing:**
- Tabs UI ("All leads" / "Lists")
- Additional Filters panel component
- Agent filter (backend supports `discovered_by_agent_id` — need to verify it's in index query)
- List filter (needs `list_id` added to backend filter — see §4)
- Fit filter (needs `fit_status` added to backend filter — see §3)
- Sort dropdown UI

**Implementation:**
1. **Backend:** Add `list_id` and `fit_status` filter params to `LeadController.index()`:
   ```php
   if ($request->filled('list_id')) {
       $query->where('list_id', $request->query('list_id'));
   }
   if ($request->filled('fit_status')) {
       $query->where('fit_status', $request->query('fit_status'));
   }
   ```
2. **Frontend Types:** Extend `LeadsListParams` with `list_id`, `fit_status`, `agent_id` 
3. **Frontend Component:** Create `LeadsFilterPanel` component with filter dropdowns
4. **Frontend Component:** Create `LeadsTabs` component (All leads / Lists)
5. **Frontend Component:** Create `LeadsSortDropdown` component
6. **Integrate into `LeadsListPage`:** Add tabs, filter panel, sort dropdown above table

**Effort:** Medium — mostly frontend UI work, small backend additions.

---

### 12. Table Columns & Row Actions (Frontend)

**PRD Ref:** §4.2

**Existing in Table:**
- Checkbox column ✓
- Contact column (name, headline, company) ✓
- Signal column (primary signal) ✓
- Score column (overall score) ✓
- Email column ✓
- Status column ✓
- Imported column (uses `last_seen_at`) ✓

**What's Missing:**
- "List" column showing assigned list name
- "Fit" column with 3-state toggle
- "Contact Now" row action button
- LinkedIn badge next to contact name
- True import timestamp (`imported_at` or `created_at`)

**Implementation:**
1. **Migration:** Add `imported_at` column to `lw_leads` (or decide to use `created_at` — recommended to use `created_at` as the import timestamp to avoid extra column):
   - **Recommendation:** Use `created_at` as the import date. No migration needed.
2. **Backend:** Include `list` (id, name, color) and `fit_status` in `LeadController.index()` response. Eager load list relationship.
3. **Frontend:** Add columns to `LeadsListPage.tsx`:
   - List column: pill/badge showing `lead.list?.name` with color
   - Fit column: 3-button toggle (Yes/Unknown/No) calling `updateFitStatus`
   - LinkedIn icon: Show when `profile_url` contains "linkedin.com"
   - Contact Now: Button that triggers action (placeholder or links to campaign add)
   - Import date: Switch from `last_seen_at` to `created_at`

**Effort:** Medium — mainly frontend column additions.

---

### 13. Bulk Actions (Frontend)

**PRD Ref:** §4.3

**Existing:**
- Checkbox selection exists in table ✓
- Bulk actions bar is a **placeholder** in `LeadsListPage.tsx`

**What's Missing:**
- Add to list bulk action (requires §4 list assignment endpoint)
- Export bulk action (requires §6 export with IDs)
- Enrich email bulk action (requires §5 batch enrichment endpoint)
- Add leads action (navigate to add leads flow)

**Implementation:**
1. **Frontend Component:** Create `BulkActionsBar` component:
   - "Add to List" → opens list picker modal → calls `lists.addLeads(listId, selectedIds)`
   - "Export" → calls `exports.csv({ lead_ids: selectedIds })` → downloads CSV
   - "Enrich Email" → calls `leads.enrichEmailBatch(selectedIds)` → shows progress
   - "Add Leads" → navigates to add leads route/modal
2. **Wire up:** Replace placeholder in `LeadsListPage.tsx` with `BulkActionsBar`
3. **State:** Use selected lead IDs from checkbox state already tracked

**Effort:** Medium — depends on §4, §5, §6 backend work being done first.

---

### 14. Lead Detail Sidebar Additions (Frontend)

**PRD Ref:** §4.4

**Currently Implemented in Sidebar:**
- Profile header (name, headline, company) ✓
- Status action buttons (shortlist, review, archive) ✓
- Primary signal display ✓
- Lead score display ✓
- Contact info (email, location, company) ✓

**Missing Sections:**

| Section | Dependencies | Effort |
|---------|-------------|--------|
| LinkedIn badge + link | None — use `profile_url` | Small |
| "Find Email" button | §5 enrichment endpoint | Small |
| Campaign section + journey | §7 campaign endpoint | Medium |
| Signal links to source content | Existing `sw_content_node_id` | Small |
| Company description + "See more" | Already in Company model `metadata` or `bio` | Small |
| AI Email / LinkedIn message | Needs AI generation service integration | Large (separate feature) |
| Basic Information section | Company model already has industry, headcount, domain | Small |
| Internal Notes | §8 notes endpoint | Small |
| Activity Logs | §9 activity logs | Medium |
| Qualification buttons (fit) | §3 fit status endpoint | Small |
| Delete lead | §10 delete endpoint | Small |
| Export dropdown | §6 existing export | Small |
| Created timestamp footer | Use `created_at` | Trivial |

**Implementation:**
1. **Expand `LeadDetailSidebar.tsx`** with new sections:
   - Add LinkedIn badge to header (check `profile_url`)
   - Add "Find Email" button calling enrichment
   - Add `CampaignSection` sub-component using `useLeadCampaigns()`
   - Add `SignalSection` with links (use `sw_content_node_id`)
   - Add `CompanySection` showing industry, headcount, domain from company data
   - Add `NotesSection` with textarea + save (uses `useUpdateLeadNotes()`)
   - Add `ActivityLogSection` with timeline UI (uses `useLeadActivityLogs()`)
   - Add footer with delete, qualification buttons, created timestamp, export
2. **AI Messages:** Defer to separate feature — requires AI generation service. Show placeholder "Coming Soon".

**Effort:** Large total — but each sub-section is small/medium. Build incrementally.

---

## Summary: New Backend Artifacts Required

### Migrations (in a single migration file)

| Change | Table | Type |
|--------|-------|------|
| Add `fit_status` column | `lw_leads` | ALTER |
| Add `email` column | `lw_leads` | ALTER |
| Add `internal_notes` column | `lw_leads` | ALTER |
| Create `lw_lead_activity_logs` | new | CREATE |

### New Enums

| Enum | Package | Values |
|------|---------|--------|
| `FitStatus` | lead-intelligence | qualified, unknown, out_of_scope |
| `ActivityLogType` | lead-intelligence | status_changed, fit_updated, note_added, enrichment_completed, campaign_added, campaign_removed, exported, deleted |

### New Models

| Model | Package | Table |
|-------|---------|-------|
| `LeadActivityLog` | lead-intelligence | lw_lead_activity_logs |

### New Controllers / Methods

| Controller | Method | Route | Package |
|-----------|--------|-------|---------|
| `LeadController` | `interactions()` | GET /leads/{id}/interactions | lead-intelligence |
| `LeadController` | `intentSignals()` | GET /leads/{id}/intent-signals | lead-intelligence |
| `LeadController` | `updateFitStatus()` | POST /leads/{id}/fit-status | lead-intelligence |
| `LeadController` | `updateNotes()` | PATCH /leads/{id}/notes | lead-intelligence |
| `LeadController` | `campaigns()` | GET /leads/{id}/campaigns | lead-intelligence |
| `LeadController` | `addToCampaign()` | POST /leads/{id}/campaigns | lead-intelligence |
| `LeadController` | `removeFromCampaign()` | DELETE /leads/{id}/campaigns/{cid} | lead-intelligence |
| `LeadController` | `activityLogs()` | GET /leads/{id}/activity-logs | lead-intelligence |
| `LeadController` | `destroy()` | DELETE /leads/{id} | lead-intelligence |
| `ListController` | `addLeads()` | POST /lists/{id}/leads | lead-watcher |
| `ListController` | `removeLeads()` | DELETE /lists/{id}/leads | lead-watcher |
| `LeadEnrichmentController` | `enrichEmail()` | POST /leads/{id}/enrich-email | lead-intelligence (new) |
| `LeadEnrichmentController` | `enrichEmailBatch()` | POST /leads/enrich-email/batch | lead-intelligence (new) |

### New Services

| Service | Package | Purpose |
|---------|---------|---------|
| `LeadActivityLogger` | lead-intelligence | Log user actions on leads |
| `LeadEnrichmentService` | lead-intelligence | Orchestrate email enrichment (adapter pattern for 3rd-party) |

---

## Summary: New Frontend Artifacts Required

### API Methods (add to `lead-watcher-api.ts`)

| Method | Endpoint |
|--------|----------|
| `leads.updateFitStatus(id, status)` | POST /leads/{id}/fit-status |
| `leads.updateNotes(id, notes)` | PATCH /leads/{id}/notes |
| `leads.getCampaigns(id)` | GET /leads/{id}/campaigns |
| `leads.addToCampaign(id, campaignId)` | POST /leads/{id}/campaigns |
| `leads.removeFromCampaign(id, campaignId)` | DELETE /leads/{id}/campaigns/{cid} |
| `leads.getActivityLogs(id)` | GET /leads/{id}/activity-logs |
| `leads.delete(id)` | DELETE /leads/{id} |
| `leads.enrichEmail(id)` | POST /leads/{id}/enrich-email |
| `leads.enrichEmailBatch(ids)` | POST /leads/enrich-email/batch |
| `lists.addLeads(listId, leadIds)` | POST /lists/{id}/leads |
| `lists.removeLeads(listId, leadIds)` | DELETE /lists/{id}/leads |

### Hooks (add to `hooks.ts`)

| Hook | Type |
|------|------|
| `useUpdateLeadFitStatus()` | Mutation |
| `useUpdateLeadNotes()` | Mutation |
| `useLeadCampaigns(id)` | Query |
| `useLeadActivityLogs(id)` | Query |
| `useDeleteLead()` | Mutation |
| `useEnrichLeadEmail()` | Mutation |
| `useEnrichLeadEmailBatch()` | Mutation |
| `useAddLeadsToList()` | Mutation |
| `useRemoveLeadsFromList()` | Mutation |

### Components (add/modify in `engage-new/src/components/leads/`)

| Component | Type |
|----------|------|
| `LeadsTabs` | New — All leads / Lists tab switcher |
| `LeadsFilterPanel` | New — Collapsible filters panel |
| `LeadsSortDropdown` | New — Sort order picker |
| `BulkActionsBar` | New — Bulk action buttons when rows selected |
| `CampaignSection` | New — Campaign journey in sidebar |
| `CompanySection` | New — Basic info section in sidebar |
| `NotesSection` | New — Internal notes textarea |
| `ActivityLogSection` | New — Activity timeline |
| `FitStatusToggle` | New — 3-button qualification toggle |
| `LeadsListPage` | Modify — Add new columns, tabs, filters, bulk actions |
| `LeadDetailSidebar` | Modify — Add all new sections |

### Types (extend in `types/`)

| Type | Change |
|------|--------|
| `LeadSummary` | Add `fit_status`, `list`, `email`, `created_at` |
| `LeadDetailResponse` | Add `internal_notes`, `fit_status`, `campaigns`, `activity_logs` |
| `LeadsListParams` | Add `list_id`, `fit_status`, `agent_id` |
| `LeadCampaignAssignment` | New type |
| `LeadActivityLog` | New type |

---

## Recommended Implementation Order

| Phase | Features | Rationale |
|-------|----------|-----------|
| **Phase 1: Backend Foundation** | §1 Interactions endpoint, §2 Intent Signals endpoint, §3 Fit Status (migration + enum + endpoint), §8 Notes (migration + endpoint), §10 Delete | Quick wins — fills existing route gaps, adds columns |
| **Phase 2: List & Export** | §4 List assignments (batch endpoints + filter), §6 Export (extend with IDs + new columns) | Enables bulk actions |
| **Phase 3: Campaign & Logs** | §7 Campaign linkage endpoint, §9 Activity logs (table + model + service + endpoint) | New infrastructure |
| **Phase 4: Frontend - Table** | §11 Filters/tabs, §12 Table columns, §13 Bulk actions | All backend endpoints ready |
| **Phase 5: Frontend - Sidebar** | §14 All sidebar sections (incremental) | Most complex UI work |
| **Phase 6: Enrichment** | §5 Email enrichment | Depends on 3rd-party service selection |

---

## Key Decisions For Developer

1. **Fit Status storage:** Column on `lw_leads` (recommended) vs. JSON in `lw_lead_organization.metadata`
2. **Import timestamp:** Use `created_at` (recommended) vs. add `imported_at` column
3. **Delete strategy:** Hard delete with cascade (recommended) vs. soft delete
4. **List assignment:** Keep single `list_id` FK (recommended, matches PRD) vs. migrate to pivot table
5. **Email column:** Add to `lw_leads` (recommended) vs. store in metadata JSON
6. **AI Messages:** Defer to separate feature (recommended) vs. stub with placeholder
7. **Enrichment provider:** Must decide which 3rd-party service before implementing §5
8. **Contact API alignment:** Deprecate `contact-api.ts` (recommended) and use `lead-watcher-api.ts` exclusively
