# Leads Page PRD (Missing Functionality Only)

## 1. Overview
This PRD covers only the missing functionality for the Leads page and backend, based on the current LW Frontend and backend packages. The route in this app is `/leads` (not `/contacts`).

## 2. Scope
### In Scope
- Leads page UI additions: filters, sorting, list/fit columns, bulk actions, detail sidebar sections.
- Frontend wiring to backend endpoints for leads, lists, export, enrichment, and lead detail data.
- Backend endpoints required to support the missing UI features.

### Out of Scope
- Rebuilding the existing leads table and basic sidebar already implemented.
- Replacing existing Lead Watcher API prefix or authentication approach.

## 3. Assumptions
- API base path remains `api/v1/lead-watcher` and routes are `/leads/*` in this app.
- Frontend uses `leadWatcherApi` for leads endpoints; contact enrichment API currently targets `/contacts/*` and must be aligned.
- Lead detail model is `Lead`/`LeadSummary` from Lead Intelligence; list model is `LeadList` from Lead Watcher.

## 4. Functional Requirements (Frontend)

### 4.1 Top Navigation and Filters
**Description:** Add the PRD tabs and additional filters panel, wired to the existing leads list query.

**Requirements:**
- Tabs: "All leads" and "Lists" at the top of the page.
- Search input continues to query leads.
- "Additional Filters" panel with fields:
  - AI Agent (dropdown)
  - List (dropdown)
  - AI Score (dropdown)
  - Intent Type (dropdown)
  - Fit (dropdown)
  - Date Range (from/to)
- Filter actions: Refresh Counts, Clear All, Close.
- Sorting dropdown: Default, Score High to Low, Score Low to High.

**Acceptance Criteria:**
- Tabs switch the view state without page reload; selected tab is visually highlighted.
- Filters update the leads list query parameters and refresh results.
- "Clear All" resets all filter inputs to defaults and reloads results.
- "Close" collapses the panel without losing the current filter values.
- Sorting changes the order of results and persists with pagination.

### 4.2 Table Columns and Row Actions
**Description:** Add missing columns and row actions to match the PRD.

**Requirements:**
- Add "List" column showing assigned list name and a checkmark indicator if assigned.
- Add "Fit" column with three-state toggle (Yes, Unknown, No).
- Add "Contact Now" row action button.
- Add LinkedIn badge/icon next to contact name when `profile_url` exists.
- Display import date using an actual import timestamp (not `last_seen_at`).

**Acceptance Criteria:**
- List column displays list name for each lead when assigned, or empty state when not assigned.
- Fit column toggles persist to backend and immediately reflect new status in table and detail sidebar.
- Contact Now button emits a user action (placeholder allowed) and does not open the detail sidebar.
- LinkedIn badge/icon appears only when a profile URL exists and opens a new tab.
- Import date displays a relative time derived from a true import timestamp field.

### 4.3 Bulk Actions
**Description:** Implement bulk actions that are currently placeholders.

**Requirements:**
- Add to list: apply selected leads to a chosen list.
- Export: allow single/multi export to CSV.
- Enrich email: trigger enrichment for selected leads (and individual rows).
- Add leads: launch the existing add leads flow or link to it.

**Acceptance Criteria:**
- Bulk actions are disabled until at least one row is selected.
- Add to list opens a list picker and updates list assignments for all selected leads.
- Export returns a CSV file for selected leads or entire filtered set if none selected.
- Enrich email triggers backend job(s) and updates row state when complete.
- Add leads navigates to or opens the existing add leads flow.

### 4.4 Lead Detail Sidebar Additions
**Description:** Expand the sidebar to include the missing PRD sections.

**Requirements:**
- Profile header with LinkedIn badge/link and "Find Email" action.
- Campaign section with journey visualization and remove-from-campaign action.
- Signal section with links to source content.
- Company description with truncation and "See more".
- AI Email and AI LinkedIn message sections with generate and copy actions.
- Basic information section (industry, company size, company URL, website, location).
- Notes and Internal Notes sections with save.
- Activity logs section with empty state.
- Footer with delete lead, qualification buttons (Yes/Unknown/No), created timestamp, export dropdown.

**Acceptance Criteria:**
- Header shows LinkedIn badge and opens profile in new tab.
- Find Email triggers enrichment and updates email display.
- Campaign section renders stage flow and supports removal.
- Signal links navigate to source content when available.
- Company description truncates around 100 chars with a "See more" toggle.
- AI message sections allow generate, edit, and copy actions.
- Internal notes save persists to backend and reloads on refresh.
- Activity logs show empty state when none, otherwise list entries.
- Footer delete removes the lead after confirmation and updates the list.
- Qualification buttons persist to backend and sync with table Fit column.

## 5. Functional Requirements (Backend)

### 5.1 Lead Interactions and Intent Signals
**Description:** Implement controller endpoints already routed.

**Endpoints:**
- `GET /leads/{id}/interactions`
- `GET /leads/{id}/intent-signals`

**Acceptance Criteria:**
- Returns last N records with pagination or limit param.
- Each record includes type, label, occurred_at, and any content reference IDs.
- Returns 404 for invalid lead ID.

### 5.2 Lead Qualification/Fit Status
**Description:** Add fit status to leads and allow updates.

**Endpoints:**
- `POST /leads/{id}/fit-status`

**Acceptance Criteria:**
- Accepts status values: `qualified`, `unknown`, `out_of_scope`.
- Persists to leads table or related metadata.
- Returns updated lead or status payload.

### 5.3 Lead List Assignments
**Description:** Add list membership support for leads.

**Endpoints:**
- `POST /lists/{id}/leads` add leads to list.
- `DELETE /lists/{id}/leads` remove leads from list.
- `GET /leads/{id}/lists` list memberships for a lead.

**Acceptance Criteria:**
- Accepts array of lead IDs and returns counts for add/remove.
- Prevents duplicates and handles missing IDs gracefully.
- Returns list assignments for a lead with list name and added_at.

### 5.4 Lead Enrichment
**Description:** Align enrichment endpoints with frontend or update frontend to use new endpoints.

**Endpoints (choose one approach):**
- Preferred: `POST /leads/{id}/enrich-email` and `POST /leads/enrich-email/batch`.
- Alternative: implement `/contacts/*` endpoints to match frontend contact API.

**Acceptance Criteria:**
- Returns enrichment result, status, and updated email when found.
- Handles already-enriched leads as no-op success.
- Batch endpoint supports up to 500 IDs per request.

### 5.5 Export
**Description:** Provide export endpoint compatible with frontend usage.

**Endpoints:**
- `GET /exports/csv` for filtered export.
- Optional: `POST /leads/export` for explicit selection.

**Acceptance Criteria:**
- Exports selected leads when IDs provided; otherwise exports current filter set.
- CSV includes name, headline, company, location, email, score, status, signals.
- Response is streamed with correct content type and filename.

### 5.6 Campaign Linkage
**Description:** Add lead-to-campaign association and status.

**Endpoints:**
- `POST /leads/{id}/campaigns` assign lead to a campaign.
- `DELETE /leads/{id}/campaigns/{campaignId}` remove lead.
- `GET /leads/{id}/campaigns` list campaign assignments and journey steps.

**Acceptance Criteria:**
- Supports multiple campaign assignments if allowed; otherwise enforces one.
- Returns campaign name, status, and step order for journey display.

### 5.7 Notes and Activity Logs
**Description:** Add internal notes and activity logs.

**Endpoints:**
- `PATCH /leads/{id}/notes` update internal notes.
- `GET /leads/{id}/activity-logs` list activity logs.

**Acceptance Criteria:**
- Notes update persists and returns updated value.
- Activity logs include type, message, created_at, and actor if available.

### 5.8 Lead Delete
**Description:** Allow deletion from sidebar.

**Endpoint:**
- `DELETE /leads/{id}`

**Acceptance Criteria:**
- Deletes lead and associated relations as defined by policy.
- Returns 204 on success.

## 6. Data Model Additions

### 6.1 Lead Fields
- `fit_status`: enum (`qualified`, `unknown`, `out_of_scope`).
- `imported_at`: timestamp.
- `internal_notes`: text.

### 6.2 Lead List Membership
- Pivot table between leads and lists with `added_at` metadata.

### 6.3 Activity Logs
- `lw_lead_activity_logs` table with:
  - `lead_id`, `type`, `message`, `actor_id`, `created_at`.

## 7. UX and Edge Cases
- If no lists exist, list dropdowns show "No lists" and disable selection.
- If enrichment fails, surface error in row and sidebar.
- If a lead has no signals or interactions, show empty state.
- If company description is missing, show "No description available".

## 8. Non-Functional Requirements
- All endpoints must respect organization scoping.
- All mutations must return updated state for optimistic UI updates.
- Debounce search to 300 ms.
- Pagination defaults to 100 rows per page with options: 25, 50, 100, 200, all.

## 9. Implementation Notes
- Frontend route is `/leads` and should not use `/contacts`.
- Prefer aligning enrichment to `/leads` endpoints to avoid duplicate contact APIs.
- Reuse existing `leadWatcherApi` and `useLeads()` hooks.
