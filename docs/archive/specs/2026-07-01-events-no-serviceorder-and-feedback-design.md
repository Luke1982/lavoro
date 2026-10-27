# Events without service orders + event feedback — Design

Date: 2026-07-01

## Goal

Two related capabilities for events (afspraken):

1. **Events without a service order.** When creating an event, allow marking it as one that does not require a werkbon, via a checkbox next to the existing "create service order on the fly" checkbox. The flag is persisted and editable.
2. **Event feedback.** Users with a new `events.provide_feedback` permission get a button (lucide `MessageCircleReply`) on every event card — desktop planner, mobile planner, and the customer-page timeline — that opens a modal where they can leave remarks and upload images against the event.

## Constraints & principles

- Remarks and images already exist as polymorphic, reusable resources (`remarkables`, `imageables` pivots, `RemarkController`, `ImageController`, `RemarksComponent.vue`, `ImageUploadComponent.vue`).
- **Reuse, don't duplicate.** The existing remark/image components and controllers are extended to work in an API (axios/JSON) mode in addition to their current Inertia (redirect-back) mode. No forked copies.
- **No new modal wrapper component.** The feedback modal reuses the existing `ModalDialog` primitive, filled with the shared remark/image components inline. Shared open/fetch/update logic lives in a composable, not a component.
- Feedback modal writes update in place (no full page reload); relevant states (badge counts) update afterward.
- Feedback button appears on any event the user can see, gated only by `events.provide_feedback` (not restricted to executing users).
- Follows project rules: PHP snake_case, authorization in Form Requests via policies, validation only in `rules()`, morph/`foreignIdFor` patterns, toggle-not-clear UI semantics.

---

## Section 1 — `no_service_order` flag on events

### Backend

- **Migration** — add `no_service_order` boolean (default `false`) to the `events` table.
- **`Event` model** — add `no_service_order` to `$fillable`; cast to `boolean`.
- **`EventStoreRequest`**
  - Add rule `'no_service_order' => 'nullable|boolean'`.
  - `withValidator`: the eventable-required check now passes when any of `create_service_order`, `eventable_id`, **or** `no_service_order` is set. Only add the `eventable_id` error when none are present.
- **`EventUpdateRequest`** — mirror the `no_service_order` rule so the flag is editable on an existing event.
- **`EventApiController::store`** — when `no_service_order` is true:
  - Do not create a `ServiceOrder` and do not resolve/attach an eventable; create the event with `eventable_type` / `eventable_id` null.
  - Skip the `$model->events()->attach()`, `advanceToPlannedStage()`, and `syncExecutingUsers` on the (absent) model.
  - Still `syncExecutingUsers` on the event itself; skip the `NewServiceOrderAssigned` notification (no order).

### Frontend — `EventEditModal.vue`

- Add `no_service_order` to the `useForm` object, initialized from `props.initial.no_service_order`.
- Add a checkbox **next to** "Maak een nieuwe werkbon aan" labelled e.g. "Geen werkbon nodig".
- The two checkboxes are mutually exclusive: checking `no_service_order` clears `create_service_order` and vice versa. When `no_service_order` is checked, the werkbon ComboBox and create-order checkbox are hidden/disabled.
- `save()` client-side guard: do not require `eventable_id` when `no_service_order` is set.
- When editing an existing event, the checkbox reflects the stored value.

---

## Section 2 — Event remarks & images (model + API)

### `Event` model

- `use RemarkableTrait;` — provides `remarks()` and `internalRemarks()`.
- Add `images()` morphToMany to `Image` via `imageable`, matching `ServiceOrder`/`Project`:
  `->withPivot('main', 'internal')->withTimestamps()`.

### API layer — content negotiation, no forked controllers

`RemarkController` and `ImageController` currently `redirect()->back()` (Inertia). Extend them to return JSON when `$request->wantsJson()`; the existing Inertia callers (which do not send `Accept: application/json`) are unaffected.

- `RemarkController@store` — on `wantsJson()`, return the created remark (with `user`) as JSON.
- `RemarkController@destroy` — on `wantsJson()`, return `204`/JSON.
- `ImageController@store` — on `wantsJson()`, return the created image(s) as JSON.
- `ImageController@destroy`, `@setMain`, `@update` — on `wantsJson()`, return JSON.

### Routes (`routes/api.php`, sanctum)

- `POST   /api/remarks`               → `RemarkController@store`
- `DELETE /api/remarks/{remark}`      → `RemarkController@destroy`
- `POST   /api/images`                → `ImageController@store`
- `DELETE /api/images/{image}`        → `ImageController@destroy`
- `POST   /api/images/{image}/set-main` → `ImageController@setMain`
- `POST   /api/images/update/{image}` → `ImageController@update`
- `GET    /api/events/{event}/feedback` → returns the event's `remarks` + `images`.

Additionally, `EventApiController@index` includes `remarks_count` and `images_count` (via `withCount`) on each event so the leaf buttons can show a badge and the planner can refresh counts after a feedback change.

### Authorization

- New `EventPolicy::provideFeedback(User, Event)` returning `hasPermission('events.provide_feedback')` (plus admin).
- The `GET /api/events/{event}/feedback` route and the remark/image writes issued from the feedback modal authorize against `events.provide_feedback`. The existing image/remark policies continue to govern the Inertia paths on other models.

---

## Section 3 — Dual-mode remark & image components

Principle: **one component, two transports.** Add `apiMode: Boolean` (default `false`). When absent, behavior is identical to today (Inertia `useForm` + redirect-back). When `true`, the same UI uses axios against the JSON API and emits results instead of relying on a page reload.

### `RemarksComponent.vue`

- New prop `apiMode` (default `false`).
- `addComment()`:
  - `apiMode`: `axios.get('sanctum/csrf-cookie')` then `axios.post('/api/remarks', payload)`; emit `created` with the returned remark; clear the textarea.
  - else: unchanged `form.post('/remarks', …)`.
- `deleteComment(id)`:
  - `apiMode`: `axios.delete('/api/remarks/' + id)`; emit `deleted` with the id.
  - else: unchanged.
- Add `defineEmits(['created', 'deleted'])`. In Inertia mode these never fire — no behavior change.

### `ImageUploadComponent.vue`

- New prop `apiMode` (default `false`).
- `uploadPhotos()`, `deleteImage()`, `setMain()`, `changeTitle()` / `saveEditedImage()`:
  - `apiMode`: build `FormData`, send via axios to the `/api/images*` routes, emit the existing events (`imagesUploaded`, `imageDeleted`, `imageUpdated`) from the axios response instead of `page.props.flash.extra`.
  - else: unchanged Inertia path.
- Parent owns the list in both modes: Inertia via prop refresh on redirect, apiMode via emitted events.

---

## Section 4 — Feedback modal via existing `ModalDialog`

No bespoke modal component. Each host surface renders `ModalDialog` directly and fills its default slot with `<RemarksComponent :api-mode>` + `<ImageUploadComponent :api-mode>`.

### `useEventFeedback.js` composable (shared logic, not a component)

- State: `open`, `activeEvent`, `remarks`, `images`.
- `openFeedback(event)` — set `activeEvent`, `GET /api/events/{id}/feedback` into `remarks`/`images`, open the modal.
- Handlers for child-component events (`created`, `deleted`, `imagesUploaded`, `imageDeleted`) that update `remarks`/`images` and expose a "changed" signal so hosts can refresh.

### Leaf buttons

lucide `MessageCircleReply`, gated on `hasPermission('events.provide_feedback')`, shown on every event. Each emits `open-feedback` with its event:

- `PlannerEvent.vue` — top-right control cluster, next to `EventExecutionControls`.
- `MobilePlannerView.vue` — event card header row.
- `EventTimelineComponent.vue` — per timeline row.

### Host placement

`ModalDialog` + the two child components live once per host, driven by `useEventFeedback`:

- Desktop planner: `ResourcePlannerWidget.vue` (owns `PlannerEvent`).
- Mobile planner: `MobilePlannerView.vue` (owns the cards).
- Customer page: `EventTimelineComponent.vue` (owns the timeline).

On the planner surfaces, refresh `fetchEvents()` when feedback changes so badge counts stay current.

### Permission migration

Seed `events.provide_feedback` (label: "Mag terugkoppeling geven op afspraken"), following the existing seed-permission migration pattern (idempotent create in `up`, delete in `down`).

---

## Section 5 — Dual-mode regression test (part of the build)

No JS test runner exists yet. Add one and gate the build on it.

### Setup

- devDependencies: `vitest`, `@vue/test-utils`, `jsdom`.
- `vitest.config.js`: jsdom environment; resolve `@` → `resources/js`.
- `package.json`: `"test": "vitest run"`; `"build": "vitest run && vite build"` so the test runs as part of the build.

### Tests (`resources/js/Components/__tests__/`)

- **`RemarksComponent`** — with no `apiMode`: clicking "Opmerking plaatsen" calls Inertia `form.post('/remarks', …)` and does **not** hit axios; `created`/`deleted` do not emit. With `apiMode: true`: hits axios, emits events, no Inertia navigation. (Inertia `useForm` and axios mocked.)
- **`ImageUploadComponent`** — with no `apiMode`: upload/delete go through Inertia `form.post`/`form.delete`, not axios. With `apiMode: true`: axios FormData path, emits, no Inertia navigation.

This locks in that the surgery is non-breaking and stays that way on every build.

---

## Out of scope

- Editing/deleting remarks or images authored by other users beyond what current policies already allow.
- Any change to how service-order-linked events currently behave.
- Google Calendar sync behavior for no-service-order events (unchanged; they sync like any other event).
