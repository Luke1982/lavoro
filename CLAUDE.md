# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Start full dev stack (Laravel, queue, log tail, Vite — all at once)
composer run dev

# Frontend only
npm run dev

# Production build
npm run build

# Run all tests
composer test

# Run a single test
php artisan test --filter=TestName

# Fix PHP code style
./vendor/bin/pint

# Fix JS/Vue linting
npm run fix:eslint
```

## Coding rules

-   PHP: snake_case for all variable names.
-   No inline comments; prefer clear names and docblocks only when needed.
-   Don't propose git commands or workflows.
-   Don't write tests unless asked.
-   In Laravel, always check authorization via Form Requests (`authorize()`) and/or policies.
-   Reuse the `userables` pivot with `type` column for role-like distinctions (`owner`, `executing`) — don't introduce parallel pivot tables.
-   When adding relationships, use proper morphs/`foreignIdFor` patterns.
-   Validation belongs in Form Request `rules()` only; frontend only displays `form.errors`.
-   Selecting/toggling in UI components: clicking a selected item deselects it — never add separate X / clear buttons.
-   String concatenation should always be done with spaces: $string . ' some other string'

## Project overview

Laravel 12 + Inertia + Vue 3 field-service management app (in Dutch, "Lavoro"). Tracks customers, assets, service orders, jobs, checks, tickets, materials, events/appointments, projects with milestones, documents, images and remarks.

## Backend (Laravel)

-   `app/Models/` — Eloquent models. Cross-model behavior is in `app/Models/Traits/`: `HasOwner`, `HasExecutingUsers`, `HasActivities`, `HasCustomFields`, `RemarkableTrait`.
-   `app/Http/Controllers/` — Inertia/web controllers return `inertia(...)`. `EventApiController` and `ProjectApiController` return JSON and live under `routes/api.php`.
-   `app/Http/Requests/` — Form Requests for every action. Auth in `authorize()` (policy or `hasPermission(...)`); validation in `rules()`. Naming: `{Resource}{Action}Request`.
-   `app/Policies/` — model policies referenced from Form Requests via `$user->can('view', $model)`.
-   `app/Enums/` — typed enums. Most expose a `comboBoxArray()` helper for frontend dropdowns.
-   `app/Services/` — domain services: PDF generation, mail, `SnelStartClient` (accounting integration).
-   `routes/web.php` — Inertia routes under the `auth` middleware group; admin block uses `admin` middleware.
-   `routes/api.php` — Sanctum-protected JSON routes (`auth:sanctum`).

### ServiceOrder stages

`ServiceOrderStage` is a user-configurable pipeline stage with three semantic booleans: `is_plannable_state`, `is_planned_state`, `is_closed_state`. `ServiceOrder::is_closed` is an appended attribute derived from `serviceOrderStage.is_closed_state`. Always go through the stage to reason about order state, not raw fields.

### Permissions

-   Roles → permissions via `permissions` and `permissionables` (polymorphic). User → roles via `roleables`.
-   `User::hasPermission(name)` returns true for admins or when granted via any role.
-   Convention: `{resource}.{action}` — e.g. `event.read`, `serviceorder.see_financials`.
-   Permissions are seeded in migrations (under `2025_09_*` and `2026_*`).

### Polymorphic patterns

All cross-model attachment uses morph-pivot tables ending in `-ables`: `eventables`, `imageables`, `remarkables`, `documentables`, `userables`, `materiables`, `activityables`, `customfieldables`, `activityables`. Each pivot links by `{model}_id`, `{model}able_type`, `{model}able_id`.

## Frontend (Inertia + Vue 3)

-   `resources/js/app.js` — Inertia bootstrap. Auto-resolves pages from `Pages/**/*.vue`; default layout is `Layouts/MainLayout.vue`.
-   `resources/js/Pages/` — Inertia pages grouped per resource (`Projects/IndexPage.vue`, `Projects/ShowPage.vue`, etc.).
-   `resources/js/Components/UI/` — shared primitives: `ComboBox`, `TextInput`, `BadgeComponent`, `EditableTextField`, `EditableGridComponent`, `ModalDialog`, `DrawerComponent`, `StepsProgressBar`, `PaginationComponent`, `SelectMenuComponent`.
-   `resources/js/Utilities/Utilities.js` — global helpers: `nlDate`, `nlTime`, `formatLocalDateAsISO`, `hasPermission`, `hasAnyPermission`, `initials`, `serviceOrderSentState/PillText/PillColorClasses`, `projectStatusClass`, `mapsLinkFromCustomer`.
-   `hasPermission` reads `usePage().props.auth.permissions` — auth/permissions are shared globally via Inertia middleware.
-   Forms use `useForm` from `@inertiajs/vue3`. Mutating API calls (non-Inertia) go through `axios`; fetch a CSRF cookie first with `axios.get('sanctum/csrf-cookie')`.

## Integrations

-   **SnelStart** — Dutch accounting SaaS. `SnelStartClient` in `app/Services/`. Console commands sync relations and articles. UI elements gate on `snelStartEnabled` prop.
-   **Google Calendar** — OAuth via `GoogleOAuthController`; webhook sync via `GoogleWebhookController`.
-   **Microsoft Graph mail** — configured via `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET` env vars (requires `Mail.Send` application permission on the Azure app registration).
