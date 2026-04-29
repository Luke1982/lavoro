## Coding rules

- For PHP, use snake_case for all variable names.
- Do not add inline comments; prefer clear names and docblocks only when needed.
- Don't propose git commands or workflows.
- In Laravel, always check authorization via Form Requests (authorize()) and/or policies.
- Reuse the userables pivot with type field for role-like distinctions (owner, executing).
- When adding relationships, use proper morphs/foreignIdFor patterns.
- Don't write tests unless I ask you to

## Project overview

Laravel 11 + Inertia + Vue 3 service-management application (in Dutch, "Lavoro"). Tracks customers, assets, service orders / jobs / checks, tickets, materials, events (appointments), projects with milestones, documents, images and remarks.

## Backend (Laravel)

- `app/Models/` — Eloquent models. Cross-model behavior is in `app/Models/Traits/` (`HasOwner`, `HasExecutingUsers`, `HasActivities`, `HasCustomFields`, `RemarkableTrait`).
- `app/Http/Controllers/` — Inertia/web controllers return `inertia(...)`. API controllers (currently `EventApiController`) return JSON and live under `routes/api.php`.
- `app/Http/Requests/` — Form Requests for every action. Auth lives in `authorize()` (policy `$user->can(...)` or direct `hasPermission(...)`); validation lives in `rules()`. Naming convention: `{Resource}{Action}Request`.
- `app/Policies/` — model policies referenced from Form Requests via `$user->can('view', $model)` etc.
- `app/Enums/` — typed enums (`EventStatusses`, `ProjectStatuses`, `ServiceOrderStates`, ...). Most expose a `comboBoxArray()` helper for frontend dropdowns.
- `app/Services/` — domain services (PDF, mail, SnelStart imports).
- `routes/web.php` — Inertia routes, all under the `auth` middleware group; admin-only block uses the `admin` middleware.
- `routes/api.php` — Sanctum-protected JSON routes (`auth:sanctum`).
- `database/migrations/` — schema. Pivot tables for polymorphic relations end in `-ables` (`eventables`, `imageables`, `remarkables`, `documentables`, `roleables`, `permissionables`, `userables`, `materiables`, `activityables`, `customfieldables`, `producttypeables`).
- `userables` pivot has a `type` column distinguishing roles like `owner` / `executing` — reuse this rather than introducing parallel pivots.
- `lang/` — translations.

### Permissions

- Roles → permissions via `permissions` and `permissionables` (polymorphic). User → roles via `roleables`.
- `User::hasPermission(name)` returns true for admins or when the named permission is granted via any role.
- Convention: permissions are `{resource}.{action}` — e.g. `event.read`, `event.see_all`, `project.read`, `projectmilestone.update`, `serviceorder.see_financials`.
- Migrations seed permissions (see migrations under `2025_09_*` and `2026_*`).

### Polymorphic patterns

- Events ↔ models: `eventables` pivot (`event_id`, `eventable_type`, `eventable_id`). `Event::serviceOrders` is a `morphedByMany`.
- Images, documents, remarks, materials, activities and custom fields all follow the same `*ables` morph-pivot pattern, attachable to many models.

## Frontend (Inertia + Vue 3)

- `resources/js/app.js` — Inertia bootstrap. Auto-resolves pages from `Pages/**/*.vue`; default layout is `Layouts/MainLayout.vue` (override per page with `page.default.layout`).
- `resources/js/Pages/` — Inertia pages, grouped per resource (e.g. `Projects/IndexPage.vue`, `Projects/ShowPage.vue`).
- `resources/js/Components/` — feature components (e.g. `CalendarWidget.vue`).
- `resources/js/Components/UI/` — shared primitives (`ComboBox`, `TextInput`, `BadgeComponent`, `EditableTextField`, `PaginationComponent`, ...).
- `resources/js/Layouts/` — `MainLayout`, `EmptyLayout`, `TwoThirdsOneThird`.
- `resources/js/Utilities/Utilities.js` — global helpers: `nlDate`, `nlTime`, `formatLocalDateAsISO`, `hasPermission`, `hasAnyPermission`, `initials`, `serviceOrderSentState/PillText/PillColorClasses`, `projectStatusClass`, `mapsLinkFromCustomer`.
- `hasPermission` reads `usePage().props.auth.permissions` — auth/permissions are shared via Inertia middleware.
- Forms use `useForm` from `@inertiajs/vue3`; mutating API calls go through `axios` (CSRF cookie fetched with `axios.get('sanctum/csrf-cookie')` first).

### Calendar

- `Components/CalendarWidget.vue` is the shared FullCalendar wrapper used on the events page (`Pages/Events/EventsIndexPage.vue`) and on the dashboard (read-only).
- It pulls events from `/api/events` (handled by `EventApiController`).
- The widget supports inline create/edit through a modal, drag-to-resize/move, and per-event status toggling.

## Conventions

- Dutch UI strings (status labels, flash messages, button text).
- Date/time helpers: backend stores datetimes; frontend uses `nlDate`/`nlTime` for display and `formatLocalDateAsISO` for query params.
- Inertia flash usage: `redirect()->back()->with('success', ...)`; surfaced via `usePage().props.flash`.
- Color/pill conventions live in `Utilities.js`.
