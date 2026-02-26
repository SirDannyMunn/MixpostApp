# Leads Page Implementation Checklist (LW Frontend)

## Scope Notes
- Route in this app should be `/leads` (not `/contacts`).
- Frontend uses Lead Watcher API prefix and lead models from the Lead Intelligence package.

## Frontend: Implemented (LW Frontend)
- [x] Leads page wrapper wired to `useLeads()` and real API pagination.
- [x] Basic table layout with columns for contact, signal, score, email, status, imported.
- [x] Row selection (single/all) and bulk actions placeholder bar.
- [x] Search input wired to `onSearch` (passes query to API).
- [x] Lead detail sidebar opens on row click with basic profile, signal, score, and contact info.
- [x] Status buttons in sidebar (shortlist/review/archive) present in UI.
- [x] Lead score badge and signal badge visuals.

## Frontend: Not Implemented Yet (from PRD)
### Top Navigation + Filtering
- [ ] Tabs for "All contacts" vs "Lists".
- [ ] Additional filters panel (AI agent, list, AI score, intent type, fit, date range).
- [ ] Filter actions (refresh counts, clear all, close).
- [ ] Sort dropdown (default, score high to low, score low to high).

### Table Columns + Row Actions
- [ ] List column with assignment + checkmark indicator.
- [ ] Fit column with three-state buttons (yes/unknown/no).
- [ ] Contact now action.
- [ ] LinkedIn badge next to contact name.
- [ ] Import date based on actual import timestamp (currently uses last_seen_at).

### Bulk Actions
- [ ] Add to list (actual functionality).
- [ ] Export (single and multi) wired to backend export.
- [ ] Enrich email (single + bulk) wired to enrichment API.
- [ ] Add leads flow (CSV/manual/import) or link to existing flow.

### Lead Detail Modal (Sidebar)
- [ ] Profile header with LinkedIn badge/link + find email action.
- [ ] Campaign section with journey visualization and remove-from-campaign.
- [ ] Signal section with deep links to source content.
- [ ] Company description with truncation and see-more.
- [ ] AI personalized email message section with generate/copy actions.
- [ ] AI personalized LinkedIn message section with generate/copy and expand/collapse.
- [ ] Basic information section (industry, company size, company URL, website, location).
- [ ] Notes + internal notes (save) sections.
- [ ] Activity logs section with empty state.
- [ ] Footer actions (delete lead, qualification buttons, created timestamp, export).

## Backend: Implemented (Packages)
- [x] Lead list, detail, and status update endpoints (`/leads`, `/leads/{id}`, `/leads/{id}/status`).
- [x] Lead discovery provenance endpoint (`/leads/{id}/provenance`).
- [x] Lead export endpoints (`/exports/csv`, `/exports/webhook`).
- [x] Lists CRUD (`/lists`) in Lead Watcher package.
- [x] Lead creation endpoint in Lead Watcher package (`POST /leads`).

## Backend: Missing or Unclear for PRD Coverage
- [ ] Lead interactions endpoint (`/leads/{id}/interactions`) is routed but not implemented in controller.
- [ ] Lead intent signals endpoint (`/leads/{id}/intent-signals`) is routed but not implemented in controller.
- [ ] Lead qualification/fit status (yes/unknown/no) update endpoint.
- [ ] List assignment endpoints for leads (add/remove to list, list membership).
- [ ] Enrichment endpoints for email/phone (frontend contact API expects `/contacts/*`).
- [ ] Contact export endpoint compatible with frontend contact API (`/contacts/export`).
- [ ] Campaign linkage endpoints for leads (assign/remove, journey status).
- [ ] Lead delete endpoint.
- [ ] Internal notes endpoints.
- [ ] Activity logs endpoint.

## Integration Gaps (Frontend <-> Backend)
- [ ] Frontend uses `/leads` endpoints; PRD mentions `/contacts`. Confirm backend routes should remain `/leads`.
- [ ] Contact enrichment API in frontend targets `/contacts` routes that do not exist in backend packages.
- [ ] Frontend list/fit/qualification UI not wired to any backend endpoints.
- [ ] Export button in UI not wired to `/exports/csv` yet.

## Suggested Next Implementation Sequence
1. Wire filters + sorting + pagination to `leadWatcherApi.leads.list` params.
2. Add list membership and fit status UI + corresponding backend endpoints.
3. Implement lead detail sections (campaign, messages, notes, activity logs) with backend support.
4. Wire enrichment flow (or align endpoints to existing backend design).
5. Wire export actions to backend export endpoints.
