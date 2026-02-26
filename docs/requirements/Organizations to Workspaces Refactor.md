# Organizations → Workspaces Refactor — Engineering Spec

## 1. Purpose

Rename the existing **Organization** concept to **Workspace** across the entire MixpostApp backend + frontend **without changing behavior**.

This is a pure terminology + schema refactor:
- Same permissions model
- Same membership model
- Same org-scoping middleware behavior
- Same endpoints, but under `/workspaces` instead of `/organizations`

## 2. Scope

### In scope
- Database schema rename:
  - Table `organizations` → `workspaces`
  - Table `organization_members` → `workspace_members`
  - Column `organization_id` → `workspace_id` across all dependent tables
  - Foreign keys + indexes updated (or intentionally left with old names if safe)
- PHP backend rename:
  - Models, controllers, policies, middleware, enums
  - Relationship helpers on `User`
  - Route parameters (`{organization}` → `{workspace}`)
  - Header/query param naming (`X-Organization-Id` → `X-Workspace-Id`, `organization_id` → `workspace_id`)
- Frontend rename:
  - Type names (`Organization` → `Workspace`), switcher component, API calls
- Tests, factories, seeders updated accordingly

### Non-goals
- No behavior changes (authorization rules, billing behavior, embedding/retrieval, etc.)
- No new endpoints beyond renaming existing ones
- No data source changes

## 3. Current Surface Area (Verified)

### Core backend files
- Model: [app/Models/Organization.php](app/Models/Organization.php)
- Pivot model: [app/Models/OrganizationMember.php](app/Models/OrganizationMember.php)
- User relationships/helpers: [app/Models/User.php](app/Models/User.php)
- Enum: [app/Enums/OrganizationRole.php](app/Enums/OrganizationRole.php)
- Policy: [app/Policies/OrganizationPolicy.php](app/Policies/OrganizationPolicy.php)
- Middleware: [app/Http/Middleware/EnsureOrganizationContext.php](app/Http/Middleware/EnsureOrganizationContext.php)
- Middleware alias: [app/Http/Kernel.php](app/Http/Kernel.php)
- Controllers:
  - [app/Http/Controllers/Api/V1/OrganizationController.php](app/Http/Controllers/Api/V1/OrganizationController.php)
  - [app/Http/Controllers/Api/V1/OrganizationMemberController.php](app/Http/Controllers/Api/V1/OrganizationMemberController.php)
  - [app/Http/Controllers/Api/V1/OrganizationSettingsController.php](app/Http/Controllers/Api/V1/OrganizationSettingsController.php)
- Routes:
  - [routes/api.php](routes/api.php)

### Frontend files (local app UI)
- Types + mock data: [resources/frontend/src/lib/organizations.ts](resources/frontend/src/lib/organizations.ts)
- Switcher UI: [resources/frontend/src/components/OrganizationSwitcher.tsx](resources/frontend/src/components/OrganizationSwitcher.tsx)
- App state: [resources/frontend/src/App.tsx](resources/frontend/src/App.tsx)
- Sidebar references: [resources/frontend/src/components/Sidebar.tsx](resources/frontend/src/components/Sidebar.tsx)

### Database
- Table creation is in:
  - [database/migrations/2025_12_12_230000_create_velocity_tables.php](database/migrations/2025_12_12_230000_create_velocity_tables.php)
  - [database/migrations/2025_12_13_000100_backfill_velocity_tables.php](database/migrations/2025_12_13_000100_backfill_velocity_tables.php)
- Many tables include `organization_id` (UUID) and org-scoped indexes.

## 4. API Rename Targets

### Old → New URLs
- `GET /api/v1/organizations` → `GET /api/v1/workspaces`
- `POST /api/v1/organizations` → `POST /api/v1/workspaces`
- `GET /api/v1/organizations/{organization}` → `GET /api/v1/workspaces/{workspace}`
- `PUT|PATCH /api/v1/organizations/{organization}` → `PUT|PATCH /api/v1/workspaces/{workspace}`
- `DELETE /api/v1/organizations/{organization}` → `DELETE /api/v1/workspaces/{workspace}`
- `GET /api/v1/organizations/{organization}/members` → `GET /api/v1/workspaces/{workspace}/members`
- `POST /api/v1/organizations/{organization}/members/invite` → `POST /api/v1/workspaces/{workspace}/members/invite`
- `PATCH /api/v1/organizations/{organization}/members/{member}` → `PATCH /api/v1/workspaces/{workspace}/members/{member}`
- `DELETE /api/v1/organizations/{organization}/members/{member}` → `DELETE /api/v1/workspaces/{workspace}/members/{member}`

### Old → New header/query params
- Header `X-Organization-Id` → `X-Workspace-Id`
- Query param `organization_id` → `workspace_id`

### Old → New org-scoped settings endpoint
- `GET /api/v1/organization-settings` → `GET /api/v1/workspace-settings`
- `PUT /api/v1/organization-settings` → `PUT /api/v1/workspace-settings`
- `POST /api/v1/organization-settings/reset` → `POST /api/v1/workspace-settings/reset`
- `GET /api/v1/organization-settings/export-for-ai` → `GET /api/v1/workspace-settings/export-for-ai`

## 5. Implementation Plan

### Phase A — Preflight (Safety)
1. Ensure tests are currently green enough to validate this refactor.
2. Take a DB snapshot / dump (mandatory because this includes renames).
3. Ensure no pending migrations.

Commands:
- `php artisan optimize:clear`
- `php artisan migrate:status`
- `php artisan test`

> Note: Your last `php artisan test` exited non-zero. We should re-run after the refactor to confirm we didn’t add regressions, but also treat pre-existing failures as a separate issue.

### Phase B — Database Migration (Rename tables/columns)

#### B1. Add a dedicated rename migration
Create a **new** migration (do not edit old migrations):
- `php artisan make:migration rename_organizations_to_workspaces`

Migration responsibilities:
1. Rename tables:
   - `organizations` → `workspaces`
   - `organization_members` → `workspace_members`
2. Rename FK columns in all tables:
   - `organization_id` → `workspace_id`
3. Ensure foreign keys and indexes still exist:
   - If using `->constrained('organizations')` previously, update to `->constrained('workspaces')`
   - Recreate any composite indexes that reference `organization_id` under `workspace_id`

#### B2. Determine affected tables (source of truth)
Use the database itself to list all occurrences of `organization_id` (recommended), then match it in migrations:

- Postgres:
  - `SELECT table_name, column_name FROM information_schema.columns WHERE column_name = 'organization_id' ORDER BY table_name;`

This will drive the “rename column” list to ensure we don’t miss anything.

#### B3. Caveat: `renameColumn` support
Laravel may require `doctrine/dbal` for column renames in some setups/drivers.
If you hit a runtime error during migration execution:
- `composer require doctrine/dbal`

#### B4. Constraint + index names
Postgres keeps constraints attached to objects when renaming tables, but:
- Constraint names may still contain `organization`.
- Index names may still contain `org`.

This is mostly cosmetic; we only need to change names if:
- Your code references specific constraint/index names (rare), or
- You strongly want schema naming consistency.

Spec default: keep names unless there is a functional reason.

### Phase C — Backend Code Rename

#### C1. Models
Rename:
- [app/Models/Organization.php](app/Models/Organization.php) → `Workspace.php` (`class Workspace`)
- [app/Models/OrganizationMember.php](app/Models/OrganizationMember.php) → `WorkspaceMember.php` (`class WorkspaceMember`)

Update Eloquent relationships:
- In `Workspace`:
  - `members()` belongsToMany should use pivot table `workspace_members`
  - `memberships()` should point to `WorkspaceMember`
- In `WorkspaceMember`:
  - rename `organization()` → `workspace()`
  - update `$fillable` from `organization_id` to `workspace_id`

#### C2. User relationship helpers
In [app/Models/User.php](app/Models/User.php):
- `organizations()` → `workspaces()`
- `organizationMemberships()` → `workspaceMemberships()`
- `isMemberOf(Organization $organization)` → `isMemberOf(Workspace $workspace)`
- `roleIn(Organization $organization)` → `roleIn(Workspace $workspace)`

#### C3. Middleware
Rename:
- [app/Http/Middleware/EnsureOrganizationContext.php](app/Http/Middleware/EnsureOrganizationContext.php) → `EnsureWorkspaceContext.php`

Behavior to preserve:
- Resolve workspace by UUID `id` **or** `slug`
- Verify current user is a member
- If no header/query and user belongs to exactly 1 workspace, auto-select it
- Otherwise return 400 with hints + list

Update inputs:
- Header: read `X-Workspace-Id`
- Query: read `workspace_id`

Optional compatibility mode (recommended if external clients exist):
- Temporarily accept old names too:
  - Header fallback: also accept `X-Organization-Id`
  - Query fallback: also accept `organization_id`

#### C4. Middleware alias
Update [app/Http/Kernel.php](app/Http/Kernel.php):
- Alias `'organization' => EnsureOrganizationContext::class` becomes `'workspace' => EnsureWorkspaceContext::class`
- Update route groups in [routes/api.php](routes/api.php) from `['organization', 'billing.access']` to `['workspace', 'billing.access']`

#### C5. Controllers
Rename controllers + update route-model binding types:
- `OrganizationController` → `WorkspaceController`
- `OrganizationMemberController` → `WorkspaceMemberController`
- `OrganizationSettingsController` → `WorkspaceSettingsController`

Update internal queries:
- `.organizations()` calls become `.workspaces()`
- Table selects `organizations.*` become `workspaces.*`
- Pivot `organization_members.role` becomes `workspace_members.role`

#### C6. Policies + enum
Rename:
- [app/Enums/OrganizationRole.php](app/Enums/OrganizationRole.php) → `WorkspaceRole.php`
- [app/Policies/OrganizationPolicy.php](app/Policies/OrganizationPolicy.php) → `WorkspacePolicy.php`

Permissions string strategy:
- Option 1 (strict rename):
  - `organization.update` → `workspace.update`
  - `organization.delete` → `workspace.delete`
- Option 2 (behavior-preserving, less churn):
  - Keep the permission strings as-is and only rename the types.

Spec default: Option 1 (rename strings) for consistency, but it requires updating any checks that reference the permission strings.

Also update `AuthServiceProvider` policy mappings (file path depends on current setup; typically `app/Providers/AuthServiceProvider.php`).

### Phase D — Routes + URLs
In [routes/api.php](routes/api.php):
- Rename all `/organizations...` routes to `/workspaces...`
- Rename route params `{organization}` → `{workspace}`
- Replace “Organization management/members” comments accordingly
- Rename `/organization-settings` endpoints to `/workspace-settings`

### Phase E — Update org-scoped resource controllers
Many controllers scope queries via `where('organization_id', $organization->id)`.
After the rename:
- Use `workspace_id`
- Use `$workspace = $request->attributes->get('workspace')`

This touches a broad set of files under `app/Http/Controllers/Api/V1` and `app/Models`.

Command to drive updates:
- `rg -n "organization_id|\bOrganization\b|organizations\(" app`

### Phase F — Seeders, Factories, Tests
Update:
- Factories in [database/factories](database/factories)
  - `OrganizationFactory` → `WorkspaceFactory`
  - `OrganizationMemberFactory` → `WorkspaceMemberFactory`
  - Any references like `'organization_id' => Organization::factory()` → `'workspace_id' => Workspace::factory()`
- Seeders in [database/seeders](database/seeders)
- Feature tests in [tests](tests)
  - Header `X-Organization-Id` → `X-Workspace-Id`
  - Model `Organization::factory()` → `Workspace::factory()`

### Phase G — Frontend rename
In [resources/frontend/src](resources/frontend/src):
- `lib/organizations.ts` → `lib/workspaces.ts`
  - rename interface `Organization` → `Workspace`
  - rename `organization_id` fields in mock objects to `workspace_id` where they represent ownership
- `components/OrganizationSwitcher.tsx` → `WorkspaceSwitcher.tsx`
- Update references in `App.tsx`, `Sidebar.tsx`, etc.

### Phase H — Docs + tinker debug scripts
Not runtime-critical, but keeps the repo consistent:
- Update docs under `docs/` and `resources/frontend/src/docs/`
- Update `tinker/debug/*.php` scripts that import/use `Organization`

Note on local debugging: use `laundryos/tinker-debug` runner (do not use `php artisan tinker --execute`).
- Run scripts with: `php artisan tinker-debug:run <script_name>`

## 6. Execution Commands (End-to-End)

From repo root:
1. Backend deps + autoload
- `composer install`
- `composer dump-autoload`

2. Run migrations
- `php artisan optimize:clear`
- `php artisan migrate`

3. Run test suite
- `php artisan test`

4. Frontend (if applicable for local UI)
- `npm install`
- `npm run build` (or your normal dev command)

## 7. Risks / Caveats

- Breaking API change: clients must update from `/organizations` to `/workspaces` and header `X-Workspace-Id`.
- DB locks: renaming columns on large tables can lock; schedule a maintenance window if production data is large.
- `renameColumn` support: may require `doctrine/dbal`.
- Route middleware alias change: anything using `'organization'` middleware must be updated to `'workspace'`.
- Permission strings: if you rename `organization.*` permissions to `workspace.*`, ensure all permission checks are updated consistently.

## 8. Validation Checklist

- Can register/login and receive a token.
- Can list workspaces for the current user.
- Can create workspace and auto-attach owner membership.
- Can use org-scoped endpoints with `X-Workspace-Id` and get correct scoping.
- `php artisan test` passes (or only fails on known pre-existing failures).

---

If you want, I can implement this spec directly after you review it (starting with the DB rename migration + backend renames, then frontend, then tests).
