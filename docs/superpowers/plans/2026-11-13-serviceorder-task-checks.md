# Checks on werkbontaken Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reusable checks and checklists on task templates, copied onto werkbon tasks (per machine or for the group), ticked off in a fold-open task box, enforced on completion, and managed in a three-column drag-and-drop editor.

**Architecture:** Templates link through two morph pivots (`service_order_task_checkables`, `service_order_task_checklistables`), and a werkbon task gets snapshot rows in `service_order_task_instance_checks`. One service (`TaskInstanceCheckService`) owns copying, units, extras and machine linking. One guard (`TaskInstanceCompletionGuard`) answers "can this task complete". Ticking is a JSON endpoint that raises `TaskCheckChanged`. The serial drawer body becomes `TaskWorkPanel.vue`, which shows inline in the monteur view and inside the drawer in the standaard view. The editor is an Inertia page with HTML cards and an SVG overlay for the lines.

**Tech Stack:** Laravel 12, Inertia, Vue 3, Tailwind, `vue-draggable-next` (already installed), axios. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-11-13-serviceorder-task-checks-design.md` (mockups: `2026-11-13-serviceorder-task-checks-editor-mockups.html`, layout B).

## Global Constraints

- Tests only for the critical rules (asked for by the product owner), in `tests/Feature/TaskChecks/`, extending `Tests\TestCase` with `Tests\Concerns\CreatesAuthenticatedUsers` (`userWithPermissions(...)`) and the existing factories. No `RefreshDatabase`.
- During the build run only those tests: `flock /tmp/claude-1000/-home-guido-nvme0n1p1-code-lavoro/test-db.lock php artisan test tests/Feature/TaskChecks`. The full suite runs once, in Task 16, after everything is built.
- Other sessions have uncommitted changes in `resources/js/Pages/ServiceOrders/ShowPage.vue`, `routes/web.php` and `docs/guide/handleiding.md`. Keep edits there small and self-contained; at commit time stage only this plan's hunks.
- No commits. After each task, show the diff of that task's files. Commit only on explicit approval, with `git commit -- <paths>` listing paths by name.
- After every edit, run `./vendor/bin/pint <file>` on each changed PHP file (never on a directory) and `npx eslint --fix <file>` on each changed JS/Vue file.
- Tenant migrations only: `database/migrations/tenant/`. Apply locally with `APP_ENV=local php artisan tenants:migrate`.
- Give unique and foreign-key indexes on morph tables explicit short names; MySQL's 64-character limit bites otherwise.
- Morph `_type` columns hold the FQCN (no morph map). Never accept a class name from the client: the editor sends `'task'` or `'checklist'` and the server maps it.
- PHP variables in snake_case; string concatenation with spaces (`$a . ' b'`); `!$x` without a space; no inline comments, docblocks only where needed.
- Authorization: Form Request `authorize()` calls a policy `can()`; no `hasPermission()` in requests. Validation only in `rules()`/`after()`; the frontend only shows `form.errors`.
- `App\Exceptions\Refusal` for refusals a person can act on (rendered as JSON 422 `{message}` for JSON requests).
- Facts go through `Signals::dispatch()`; listeners in `app/Listeners` are discovered by type hint.
- Selecting toggles: clicking a selected card or pill deselects it; no X or clear buttons.
- Artisan and tinker against the dev install need `APP_ENV=local`. Run scripts as `APP_ENV=local php artisan tinker --execute "require '<file>';" < /dev/null` and wrap tenant code in `\App\Support\Tenancy::within($tenant, fn () => ...)`. The demo tenant is `Tenant::on('central')->where('name', 'Demo')->first()`.
- Dev app: `./scripts/tenancy/dev.sh` (http://127.0.0.1:8199, demo login `demo@lavorofsm.nl` / `demo`). Browser checks use playwright-core from `~/.npm/_npx/e41f203b7505f1fb/node_modules/playwright-core` with Chromium at `~/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome`. Wait for `networkidle` on /login before filling it.
- UI copy is Dutch; use the exact strings below.

## Review Focus

1. **Fast taps on a bad connection.** A mechanic taps three checks in two seconds and one request fails. Only that check reverts, the others stay ticked, and the message shows. (Task 9, verification step.)
2. **A template that changes after the werkbon task was made.** A check is removed from a checklist, or deleted from the library. The werkbon task keeps its rows and titles, and completion still needs the snapshot's required checks. (Task 12, verification step.)
3. **Lowering the aantal with a ticked middle unit.** 3 units, unit 2 ticked, unit 3 empty: lowering to 2 removes unit 3. Units 2 and 3 ticked: lowering to 2 is refused, naming machine 3. (Task 5, verification step.)
4. **A check reached by two routes.** It sits loose on a task (optional) and in a checklist on that task (required). It's copied once, required, under the checklist. (Task 2, verification step.)
5. **A plain task without checks or product.** It still completes with one tap in both views. Nothing folds open and no drawer appears. (Task 10, verification step.)

---

## File map

| File | Responsibility |
|---|---|
| `database/migrations/tenant/2026_11_13_200000_create_service_order_task_checks_tables.php` | The 5 tables and the `checks_per_unit` column |
| `app/Models/ServiceOrderTaskCheck.php`, `ServiceOrderTaskChecklist.php` | Library entries |
| `app/Models/ServiceOrderTaskCheckLink.php`, `ServiceOrderTaskChecklistLink.php` | The pivot rows as models (they have ids, flags, order) |
| `app/Models/ServiceOrderTaskInstanceCheck.php` | A check on a werkbon task (snapshot) |
| `app/Services/TaskInstanceCheckService.php` | Template set, seeding, units, extras, machine linking, labels |
| `app/Services/TaskInstanceCompletionGuard.php` | The single answer to "may this task complete" |
| `app/Actions/Tasks/CreateTaskInstanceAction.php` | Create a werkbon task with its checks (controller and assistant) |
| `app/Http/Controllers/ServiceOrderTaskInstanceCheckApiController.php` | Tick / untick (JSON) |
| `app/Domain/Signals/Tasks/TaskCheckChanged.php` | The tick fact for the timeline |
| `app/Listeners/Tasks/LinkTaskChecksToMachines.php` | Machines registered → link unit checks |
| `app/Http/Controllers/ServiceOrderTaskCheck*Controller.php` (4) | Editor page and its writes |
| `resources/js/Components/ServiceOrders/Tasks/taskSerials.js` | Serial helpers moved out of the widget |
| `resources/js/Components/ServiceOrders/Tasks/TaskWorkPanel.vue` | Serials + checks + **Taak voltooien** for one task |
| `resources/js/Composables/useTaskChecks.js` | Units, progress, optimistic ticking |
| `resources/js/Pages/ServiceOrderTaskChecks/EditorPage.vue` | The editor |
| `resources/js/Components/TaskChecks/*` | Editor column, card, lines, popover, contents |

---

### Task 1: Tables and models

**Files:**
- Create: `database/migrations/tenant/2026_11_13_200000_create_service_order_task_checks_tables.php`
- Create: `app/Models/ServiceOrderTaskCheck.php`, `app/Models/ServiceOrderTaskChecklist.php`, `app/Models/ServiceOrderTaskCheckLink.php`, `app/Models/ServiceOrderTaskChecklistLink.php`, `app/Models/ServiceOrderTaskInstanceCheck.php`
- Modify: `app/Models/ServiceOrderTask.php`, `app/Models/ServiceOrderTaskInstance.php`

**Interfaces:**
- Produces tables exactly as in the spec's "Data model". On the link tables, foreign keys and morphs cascade on delete. On `service_order_task_instance_checks`: `service_order_task_instance_id` cascades; `service_order_task_check_id`, `service_order_task_checklist_id`, `asset_id` and `checked_by` (users) null on delete. `is_required`, `is_extra` and `is_done` default false; `checklistables.is_required` defaults true. Unique index names: `sotc_check_checkable_unique`, `sotcl_list_checklistable_unique`.
- `service_order_task_instances.checks_per_unit` boolean default false, after `quantity`.
- `ServiceOrderTaskCheck`: `$fillable = ['title']`; `checkLinks(): HasMany<ServiceOrderTaskCheckLink>`.
- `ServiceOrderTaskChecklist`: `$fillable = ['title']`; `checks(): MorphToMany` (`morphToMany(ServiceOrderTaskCheck::class, 'checkable', 'service_order_task_checkables')->withPivot('id', 'is_required', 'sort_order')->orderByPivot('sort_order')`); `checkLinks(): MorphMany<ServiceOrderTaskCheckLink>` (`morphMany(..., 'checkable')`); `taskLinks(): HasMany<ServiceOrderTaskChecklistLink>`.
- `ServiceOrderTaskCheckLink` (table `service_order_task_checkables`): fillable `service_order_task_check_id, checkable_type, checkable_id, is_required, sort_order`; `check(): BelongsTo`; `checkable(): MorphTo`.
- `ServiceOrderTaskChecklistLink` (table `service_order_task_checklistables`): fillable `service_order_task_checklist_id, checklistable_type, checklistable_id, is_required, sort_order`; `checklist(): BelongsTo`; `checklistable(): MorphTo`.
- `ServiceOrderTask`: `checks(): MorphToMany` (same pivot definition as above); `checkLinks(): MorphMany`; `checklists(): MorphToMany` (`morphToMany(ServiceOrderTaskChecklist::class, 'checklistable', 'service_order_task_checklistables')->withPivot('id', 'is_required', 'sort_order')->orderByPivot('sort_order')`); `checklistLinks(): MorphMany`.
- `ServiceOrderTaskInstanceCheck`: fillable all columns except id/timestamps; casts `is_required, is_extra, is_done` bool, `checked_at` datetime, `unit_index` integer; `instance(): BelongsTo` (`service_order_task_instance_id`), `check()`, `checklist()`, `asset()`, `checkedBy()` (User, `withTrashed()`). No `RecordsHistory`: the signal is the trail.
- `ServiceOrderTaskInstance`: add `checks_per_unit` to `$fillable`, `$casts` (bool) and `$activity_labels` (`'Checks per machine'`); `checks(): HasMany` ordered by `unit_index`, then `sort_order`.

- [ ] **Step 1:** Write the migration and the five models, plus the two model changes.
- [ ] **Step 2:** Run `APP_ENV=local php artisan tenants:migrate`. Expected: the migration runs for all three local tenants without errors.
- [ ] **Step 3:** Write a scratchpad script that, inside the demo tenant and a `DB::transaction` it rolls back at the end, creates check "Rooster schoongemaakt", checklist "Checklist oplevering" with that check (required), and links the checklist to the first `ServiceOrderTask` as optional. It then prints `$task->checklists->first()->pivot->is_required`, `$task->checklists->first()->checks->pluck('title')`, and `ServiceOrderTaskCheck::first()->checkLinks()->count()`. Expected: `false`, `["Rooster schoongemaakt"]`, `1`.
- [ ] **Step 4:** Run pint on every file from this task and show the diff.

---

### Task 2: Copying checks when a task is created

**Files:**
- Create: `app/Services/TaskInstanceCheckService.php`, `app/Actions/Tasks/CreateTaskInstanceAction.php`
- Modify: `app/Http/Controllers/ServiceOrderTaskInstanceController.php` (`store`), `app/Http/Requests/ServiceOrderTaskInstanceStoreRequest.php`, `app/Domain/Tools/Write/AddServiceOrderTaskTool.php:190-192`

**Interfaces:**
- Consumes: Task 1 models.
- Produces, on `TaskInstanceCheckService`:
  - `templateSet(?ServiceOrderTask $task): Collection`. Each item is `array{service_order_task_check_id: ?int, service_order_task_checklist_id: ?int, title: string, checklist_title: ?string, is_required: bool, is_extra: bool}`. The order is the task's checklists (by pivot `sort_order`) with their checks in order, then the task's loose checks. A check reached more than once appears once, at its first position, with `is_required` = OR over its routes. A route through a checklist is required only when both the checklist link and the check's link inside the list are required.
  - `extraSet(array $check_ids, array $checklist_ids, array $titles): Collection` has the same shape, with `is_required = false` and `is_extra = true`. Checklists expand to their checks with `checklist_title` set. Free titles have both ids null.
  - `unitCount(ServiceOrderTaskInstance $instance): int` returns `max(1, (int) $instance->quantity)` when `product_id` is set, otherwise 1.
  - `seed(ServiceOrderTaskInstance $instance, array $extras = []): void`. `$extras` has keys `check_ids`, `checklist_ids` and `titles`. It writes the template set, then the extras whose `service_order_task_check_id` is not already in the template set, once per unit (`unit_index` 0..n-1) when `checks_per_unit`, otherwise once with `unit_index` null. `sort_order` counts up per unit.
- Produces `CreateTaskInstanceAction::execute(array $attributes, array $extras = []): ServiceOrderTaskInstance`. It does `ServiceOrderTaskInstance::create($attributes)` and `seed()` in one `DB::transaction`.
- Store request: new rules `checks_per_unit` (`sometimes|boolean`), `extra_check_ids` / `extra_checklist_ids` (`sometimes|array`, items `integer|exists:<table>,id`), `extra_titles` (`sometimes|array`, items `string|max:255`). New `after()` rule: when `is_complete` is true and `templateSet(task)` has a required item, add the error on `is_complete`: `Deze taak heeft verplichte checks en kan niet als voltooid worden aangemaakt.`
- The controller's `store` removes the three `extra_*` keys from `$data` and calls the action with `['check_ids' => …, 'checklist_ids' => …, 'titles' => …]`. Roles and flex quantities stay as they are, after the action.
- `AddServiceOrderTaskTool` replaces `ServiceOrderTaskInstance::create([...] + $spec)` with `app(CreateTaskInstanceAction::class)->execute(['service_order_id' => $order->id] + $spec)`.

- [ ] **Step 1:** Implement the service methods above and the action.
- [ ] **Step 2:** Wire the store request, the controller and the assistant tool.
- [ ] **Step 3:** Scratchpad script (demo tenant, rolled back). Build template T with:
  - checklist L (required on T) holding A (required) and B (optional);
  - loose check B on T (optional);
  - loose check C on T (required).

  Create an instance of T with a product, `quantity` 3, `checks_per_unit` true and extra title "Foto gemaakt". Print `checks->groupBy('unit_index')->map->pluck('title')` and the `is_required` of B and C. Expected: 3 units, each `["A","B","C","Foto gemaakt"]`, with B false and C true. Then make B in L required and L's link to T optional, create again with `checks_per_unit` false, and print the same. Expected: one group of 4, with A false (optional through L), B false (optional through L and loose), C true. (Review Focus 4: change B's loose link to required, and B must come out true.)
- [ ] **Step 4:** Write `tests/Feature/TaskChecks/CopyChecksTest.php`:
  - `test_a_check_through_an_optional_checklist_is_optional`
  - `test_a_check_reached_twice_is_copied_once_and_required_if_any_route_is`
  - `test_per_machine_copies_one_set_per_unit` (3× product → `unit_index` 0, 1, 2)
  - `test_extras_are_optional_and_skip_checks_already_copied`
  - `test_a_task_with_required_checks_cannot_be_created_complete` (POST `/serviceordertaskinstances` with `is_complete` true → session error on `is_complete`, no instance)
  - `test_the_assistant_tool_copies_checks` (via `AddServiceOrderTaskTool`, as in `tests/Feature/Assistant/ServiceOrderTaskToolTest.php`)
- [ ] **Step 5:** Run the TaskChecks tests under flock. Expected: all pass.
- [ ] **Step 6:** Run pint on every file from this task and show the diff.

---

### Task 3: One completion guard

**Files:**
- Create: `app/Services/TaskInstanceCompletionGuard.php`
- Modify: `app/Http/Controllers/ServiceOrderTaskInstanceController.php` (`toggle`, the `$data['is_complete'] && product_id` block)

**Interfaces:**
- Consumes: `TaskInstanceSerialSlotService::allSlotsFilled`, `ServiceOrderTaskInstance::checks()`.
- Produces `TaskInstanceCompletionGuard::problem(ServiceOrderTaskInstance $instance): ?string`. It returns `Registreer eerst alle machines voordat je deze taak voltooit.` when slots are open, then `Vink eerst alle verplichte checks af.` when any `is_required && !is_done` row exists, otherwise null. It loads what it needs itself (the same `loadMissing` list `toggle` uses today).
- `toggle` calls it when completing and returns `withErrors(['task' => $problem])`, the same shape the frontend already handles.

- [ ] **Step 1:** Implement the guard and replace the inline slot check in `toggle`.
- [ ] **Step 2:** Scratchpad script (rolled back): an instance with one required, unticked check gets `problem()` = the checks message; after ticking it, `null`. An instance with a product and no assets still gets the machines message first.
- [ ] **Step 3:** Write `tests/Feature/TaskChecks/CompletionGuardTest.php`:
  - `test_completing_is_refused_while_a_required_check_is_open` (PATCH `/serviceordertaskinstances/{id}/toggle` → session error `task` = `Vink eerst alle verplichte checks af.`, still incomplete)
  - `test_open_optional_checks_do_not_block_completion`
- [ ] **Step 4:** Run the TaskChecks tests under flock. Expected: all pass.
- [ ] **Step 5:** Run pint on every file from this task and show the diff.

---

### Task 4: Ticking a check

**Files:**
- Create: `app/Http/Controllers/ServiceOrderTaskInstanceCheckApiController.php`, `app/Http/Requests/ServiceOrderTaskInstanceCheckUpdateRequest.php`, `app/Policies/ServiceOrderTaskInstanceCheckPolicy.php`, `app/Domain/Signals/Tasks/TaskCheckChanged.php`
- Modify: `routes/api.php` (inside the `auth:sanctum` group), `app/Services/TaskInstanceCheckService.php`

**Interfaces:**
- Route: `PATCH /api/serviceordertaskinstancechecks/{serviceordertaskinstancecheck}`, named `serviceordertaskinstancechecks.update`.
- Policy `tick(User $user, ServiceOrderTaskInstanceCheck $check): bool` returns `hasPermission('serviceordertaskinstance.open_close') || hasPermission('serviceordertaskinstance.update')` (parity with `toggle`). The request's `authorize()` returns `$this->user()->can('tick', $this->route('serviceordertaskinstancecheck'))`. Rules: `is_done` `required|boolean`.
- The controller throws `Refusal` with:
  - `De werkbon is gesloten.` when the order `is_closed`;
  - `Deze taak is geannuleerd.` when cancelled;
  - `Deze taak is al voltooid; heropen hem om checks te wijzigen.` when complete.

  Otherwise it sets `is_done`, `checked_at` (`now()`/null) and `checked_by` (`auth()->id()`/null), dispatches the signal and returns `{id, is_done, checked_at, checked_by_name}`.
- Service: `machineLabel(ServiceOrderTaskInstanceCheck $check): ?string` returns null for `unit_index` null. Otherwise it returns `machine N` (N = 1-based position of this `unit_index` among the instance's distinct unit indexes), followed by ` (SERIAL)` when the linked asset has a serial.
- `TaskCheckChanged(ServiceOrder $service_order, ServiceOrderTaskInstance $task_instance, string $task_title, ServiceOrderTaskInstanceCheck $check, ?string $machine_label, bool $is_done)`:
  - key `taskinstance.check_changed`, label `Check afgevinkt`, category `status`;
  - `subject()` is the service order, `activityContext()` is `array_filter([$service_order->customer])`;
  - `activityDescription()` reads `Check "{title}" afgevinkt bij taak "{task}"` or `… uitgevinkt …`, followed by `, {machine_label}` when set;
  - `changes()` is one row: `field` `is_done`, `label` the check title, values `'0'`/`'1'`, labels `Open`/`Afgevinkt`.

  Task title comes from `RegisterTaskAssetsAction::titleFor($instance)`.

- [ ] **Step 1:** Implement the route, policy, request, controller, signal and `machineLabel`.
- [ ] **Step 2:** With the dev app running, log in as demo in playwright. Call the endpoint with `page.request.patch` (after `GET /sanctum/csrf-cookie`, sending the `X-XSRF-TOKEN` header from the cookie) on a check of an open task. Expected: 200 with `is_done: true` and `checked_by_name: "Sanne de Vries"`. On a check of a completed task: 422 `{message: "Deze taak is al voltooid; heropen hem om checks te wijzigen."}`.
- [ ] **Step 3:** Open that werkbon. Expected: the Tijdlijn shows `Check "…" afgevinkt bij taak "…"`. The customer's page shows the same entry.
- [ ] **Step 4:** Write `tests/Feature/TaskChecks/TickCheckTest.php`:
  - `test_ticking_records_who_and_when_and_writes_the_timeline` (JSON 200; `checked_by` is the user; an activity row with the `Check "…" afgevinkt bij taak "…"` description exists on the order)
  - `test_ticking_on_a_completed_task_is_refused` (422, the exact message, row unchanged)
  - `test_ticking_needs_permission` (user without `open_close`/`update` → 403)
- [ ] **Step 5:** Run the TaskChecks tests under flock. Expected: all pass.
- [ ] **Step 6:** Run pint on every file from this task and show the diff.

---

### Task 5: Editing a task: units, mode switch, extras

**Files:**
- Modify: `app/Services/TaskInstanceCheckService.php`, `app/Http/Requests/ServiceOrderTaskInstanceUpdateRequest.php`, `app/Http/Controllers/ServiceOrderTaskInstanceController.php` (`update`)

**Interfaces:**
- Service:
  - `hasTicks(ServiceOrderTaskInstance $instance): bool`.
  - `blockingUnit(ServiceOrderTaskInstance $instance, int $unit_count): ?int`. When `checks_per_unit` and the current unit count exceeds `$unit_count`, units are removed highest index first, skipping units that have a linked asset or a ticked check. If fewer than needed can go, it returns the 1-based position of the highest blocking unit, otherwise null.
  - `syncUnits(ServiceOrderTaskInstance $instance): void` adds units by copying unit 0's rows unticked, with `asset_id` null, or removes them using the same order as `blockingUnit`.
  - `rebuild(ServiceOrderTaskInstance $instance): void` handles the per-unit switch. It takes one unit's set (or the group's set), deletes every row, and lays the set out again for the new mode.
  - `syncExtras(ServiceOrderTaskInstance $instance, array $extras): void` treats `$extras` as the desired full set. Extra rows whose source (check id, checklist id, or free title) is no longer wanted are deleted on every unit; missing ones are appended to every unit.
- Request rules: the same three `extra_*` keys as the store request, plus `checks_per_unit` `sometimes|boolean`.
- Request `after()` additions, run before the existing machine checks:
  - Switching `checks_per_unit` while `hasTicks` adds an error on `checks_per_unit`: `Er zijn al checks afgevinkt; dit kan niet meer wijzigen.`
  - Compute the new unit count from the posted (or current) `product_id` and `quantity`. If `blockingUnit` returns N, add an error on `quantity`: `Bij machine N zijn al checks afgevinkt; kies een hoger aantal.`
  - Extras are refused on a completed task with an error on `extra_check_ids`: `Deze taak is al voltooid.`
- Controller `update`, after `$serviceordertaskinstance->update($data)`:
  - if `checks_per_unit` changed, call `rebuild()`, otherwise `syncUnits()`;
  - when any `extra_*` key is present, call `syncExtras()`;
  - then call `linkMachines()` (Task 6; leave the call out until Task 6 lands).

  Remove the `extra_*` keys from `$data` before `update()`.

- [ ] **Step 1:** Implement the service methods, the request rules and the controller wiring.
- [ ] **Step 2:** Scratchpad script (rolled back), Review Focus 3. Take a 3-unit per-unit instance with unit 1 (index 1) ticked.
  - `blockingUnit(…, 2)` returns null; after `quantity = 2` and `syncUnits`, the remaining indexes are `[0, 1]`.
  - Tick index 2 as well and restore 3 units: `blockingUnit(…, 2)` returns 3.
  - With no ticks, `rebuild` to group mode leaves one set with `unit_index` null.
- [ ] **Step 3:** Write `tests/Feature/TaskChecks/EditTaskChecksTest.php`:
  - `test_lowering_the_aantal_removes_the_highest_free_unit`
  - `test_lowering_the_aantal_is_refused_when_ticked_units_would_go` (error on `quantity`: `Bij machine 3 zijn al checks afgevinkt; kies een hoger aantal.`)
  - `test_switching_per_machine_is_locked_after_a_tick`
  - `test_removing_an_extra_removes_it_from_every_unit`
- [ ] **Step 4:** Run the TaskChecks tests under flock. Expected: all pass.
- [ ] **Step 5:** Run pint on every file from this task and show the diff.

---

### Task 6: Linking unit checks to their machine

**Files:**
- Create: `app/Listeners/Tasks/LinkTaskChecksToMachines.php`
- Modify: `app/Domain/Signals/Tasks/TaskAssetsRegistered.php`, `app/Actions/Tasks/RegisterTaskAssetsAction.php`, `app/Http/Requests/ServiceOrderTaskInstanceAssetStoreRequest.php`, `app/Services/TaskInstanceCheckService.php`, `app/Http/Controllers/ServiceOrderTaskInstanceController.php` (`update`, add the `linkMachines` call)

**Interfaces:**
- `TaskAssetsRegistered` gets a fifth constructor parameter, `public array $unit_indexes = []` (asset id → unit index). The key is unchanged.
- `RegisterTaskAssetsAction::execute` rows accept an optional `unit_index`. The action passes `[created asset id => row unit_index]` for rows that carry one.
- Asset store request: `assets.*.unit_index` `sometimes|nullable|integer|min:0`.
- Service: `linkMachines(ServiceOrderTaskInstance $instance, array $unit_indexes = []): void`. It does nothing unless `checks_per_unit`.
  - Bundle product: each unit index i without an `asset_id` gets `TaskInstanceBundleService::containers($instance)[i]`'s id.
  - Plain product: first the explicit pairs, but only when that unit has no asset yet and the asset isn't linked to another unit. Then the instance's remaining unlinked top-level assets (`parent_asset_id` null), oldest id first, fill the lowest unlinked unit indexes.
- Listener `handle(TaskAssetsRegistered $signal): void` calls `linkMachines($signal->task_instance, $signal->unit_indexes)`. It runs inside the transaction, so no after-commit is needed.

- [ ] **Step 1:** Implement the signal parameter, the action pass-through, the request rule, the service method, the listener and the `update` call.
- [ ] **Step 2:** Scratchpad script (rolled back): a 3-unit per-unit plain instance; register one asset through the action with `unit_index` 2. Expected: index 2's rows have that `asset_id`, and 0 and 1 have none. Register one more asset without `unit_index`. Expected: it lands on index 0.
- [ ] **Step 3:** Same script with the assistant path: `AddServiceOrderTaskTool` placeholders on a per-unit instance. Expected: units 0..n-1 are linked to the placeholders in id order.
- [ ] **Step 4:** Write `tests/Feature/TaskChecks/LinkMachinesTest.php`:
  - `test_a_machine_registered_for_a_unit_is_linked_to_that_unit`
  - `test_a_machine_without_a_unit_fills_the_lowest_free_unit`
- [ ] **Step 5:** Run the TaskChecks tests under flock. Expected: all pass.
- [ ] **Step 6:** Run pint on every file from this task and show the diff.

---

### Task 7: Sending checks to the werkbon page

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php` (`show`: the `taskInstances.*` eager-load list near line 209, and the props near line 343)

**Interfaces:**
- Eager load `taskInstances.checks.checkedBy:id,name`. Every serialized check carries `checked_by` as `{id, name}` or null.
- New props: `taskChecks` (`ServiceOrderTaskCheck::orderBy('title')->get(['id', 'title'])`) and `taskChecklists` (`ServiceOrderTaskChecklist::orderBy('title')->get(['id', 'title'])`).

- [ ] **Step 1:** Add the eager load and the props.
- [ ] **Step 2:** In playwright, load a werkbon with a seeded task and read `JSON.parse(document.getElementById('app').dataset.page).props`. Expected: `serviceOrder.task_instances[i].checks` is an array with `title`, `unit_index`, `is_required`, `is_done` and `checked_by`; `taskChecks` and `taskChecklists` are present.
- [ ] **Step 3:** Run pint on every file from this task and show the diff.

---

### Task 8: Moving the serial drawer's body into `TaskWorkPanel`

**Files:**
- Create: `resources/js/Components/ServiceOrders/Tasks/taskSerials.js`, `resources/js/Components/ServiceOrders/Tasks/TaskWorkPanel.vue`
- Modify: `resources/js/Components/ServiceOrders/TaskInstancesWidget.vue` (the serial drawer at lines 262-341 and the serial functions from line 828 down)

**Interfaces:**
- `taskSerials.js` exports, unchanged in behavior: `serialCounts(instance)`, `bundleParts(instance)`, `buildSerialGroups(instance)` and `rowIsDirty(row)`.
- `TaskWorkPanel.vue` props: `instance: Object` (required), `canToggle: Boolean`. Emits `complete` (the user asked to complete) and `saved`. It owns the serial row state, `saveRow`, `saveSerials` and `syncSerialGroups`, re-syncing when `props.instance` changes. It renders the serial groups exactly as the drawer did, plus a bottom button row: the primary button with today's labels (`Opslaan` / `Voltooien` / `Opslaan en voltooien`). On `complete: true` it emits `complete` instead of toggling itself.
- The widget's drawer becomes `<DrawerComponent v-model="serialDrawerOpen" title="Taak uitvoeren" …><TaskWorkPanel :instance="serialInstance" :can-toggle="canToggle" @complete="doToggle(serialInstance, true)" /></DrawerComponent>`, with a footer holding only **Sluiten**. `summaries`, `toggleComplete` and `SerialProgressBadge` use the imported helpers.

- [ ] **Step 1:** Move the helpers and the drawer body; keep the drawer working through the panel.
- [ ] **Step 2:** Browser, standaard view, demo werkbon 197 (task "Test", 3× Daikin 3MXM52A): open the drawer from the serial badge, type one serial and save it. Expected: the row shows the green check and the badge reads 1/3. Type the other two and press **Opslaan en voltooien**. Expected: the task shows **Voltooid**. Afterwards, reset the task with a scratchpad script so the demo data is as before.
- [ ] **Step 3:** Run eslint on every file from this task and show the diff.

---

### Task 9: Checks inside the panel

**Files:**
- Create: `resources/js/Composables/useTaskChecks.js`
- Modify: `resources/js/Components/ServiceOrders/Tasks/TaskWorkPanel.vue`

**Interfaces:**
- `useTaskChecks(instance: Ref<Object>)` returns:
  - `units: ComputedRef<Array<{key: string, index: number|null, position: number, label: string|null, assetId: number|null, checks: Array}>>`. It groups `instance.checks` by `unit_index`. The label is `{product label} · {position}/{count}`, or null for the group.
  - `progress: ComputedRef<{done: number, total: number}>`.
  - `openRequired: ComputedRef<number>`.
  - `tick(check, value: boolean): Promise<void>`.
- `tick` sets the value at once, marks the check `pending` (its checkbox is disabled while pending), then calls `axios.get('/sanctum/csrf-cookie')` (once per page) and `axios.patch('/api/serviceordertaskinstancechecks/' + id, {is_done})`. On success it stores `checked_at` and `checked_by` from the response. On failure it reverts only that check and sets `usePage().props.flash.error` to `error.response.data.message ?? 'Opslaan is mislukt.'`.
- Panel layout:
  - **Whole group:** all serial groups first, then the checks under their `checklist_title` headings.
  - **Per machine:** a block per unit with its label. For a bundle, the block shows the serial groups whose `container_index === unit.index`. For a plain product, it shows the slot row of `unit.assetId`, or one empty row whose save sends `unit_index: unit.index`. The unit's checks follow.
  - Each check is a checkbox with its title, a `*` when required, and `nlTime(checked_at)` on the right when ticked.
  - Under the button: `Nog {n} verplichte check(s) open` and/or `Nog {n} machine(s) te registreren` while the button is disabled.
  - The primary button reads **Taak voltooien** when there's nothing left to save, and is enabled only when slots are full and `openRequired === 0`.

- [ ] **Step 1:** Implement the composable and the panel changes.
- [ ] **Step 2:** Browser (standaard drawer) on a werkbon task seeded per machine, with 2 units and 2 required checks each. Tick all four. Expected: the times show and the button enables after the serials. Reload. Expected: the ticks and times persist.
- [ ] **Step 3 (Review Focus 1):** In playwright, route `**/api/serviceordertaskinstancechecks/*` to abort for the second check only, then tick three checks quickly. Expected: checks 1 and 3 stay ticked, check 2 reverts, and the error message shows.
- [ ] **Step 4:** Run eslint on every file from this task and show the diff.

---

### Task 10: The monteur view folds open

**Files:**
- Modify: `resources/js/Components/ServiceOrders/TaskInstancesWidget.vue` (the `compact` block at lines 24-34 and `toggleComplete`)

**Interfaces:**
- `hasWork(instance)` is true when `instance.checks?.length` or `serialCounts(instance).expected`.
- Compact mode:
  - The `WorkItemRow` for a task with work gets `action-label="Uitvoeren"` and `:action-icon="Play"`. Its `@action` toggles `openInstanceId`: the same task again closes it.
  - Under the row, inside a wrapper using `grid-template-rows: 0fr → 1fr` with a 200 ms transition, sits `<TaskWorkPanel :instance="instance" :can-toggle="canToggle" @complete="doToggle(instance, true)" />`.
  - The collapsed row shows `{done}/{total}` next to the serial badge when the task has checks.
  - A task without work keeps `Afvinken` and its one-tap toggle.
- Standaard mode:
  - The row shows `Checks {done}/{total}` under the product line.
  - `toggleComplete` opens the drawer instead of toggling when `openRequired > 0` (the same pattern as the serial case).

- [ ] **Step 1:** Implement the compact fold-open and the standaard additions.
- [ ] **Step 2:** Browser at 390×844 in the monteur view with a per-machine task. Tap **Uitvoeren**. Expected: the box folds open in place with no drawer; ticking doesn't collapse it or move the scroll; **Taak voltooien** completes it and the row shows **Afgevinkt**.
- [ ] **Step 3 (Review Focus 5):** On a task with neither checks nor product: one tap on **Afvinken** completes it in the monteur view, and the checkbox completes it in the standaard view. Nothing folds open and no drawer appears.
- [ ] **Step 4:** Run eslint on every file from this task and show the diff.

---

### Task 11: The per-machine choice and extras in the add and edit drawers

**Files:**
- Modify: `resources/js/Components/ServiceOrders/TaskInstancesWidget.vue` (add drawer lines 130-195, edit drawer lines 198-259, `addInstance`, `openEditDrawer`, `saveEdit`), `resources/js/Pages/ServiceOrders/ShowPage.vue` (pass `taskChecks` and `taskChecklists`)

**Interfaces:**
- Widget props: `taskChecks: Array`, `taskChecklists: Array` (default `[]`).
- Both drawers get:
  - A `SwitchComponent` labelled **Checks per machine afvinken**, shown when a product is chosen and the aantal is > 1. It's bound to `checks_per_unit`. In the edit drawer it's disabled when any check on the instance `is_done`, and the request's error shows under it.
  - **Extra checks (optioneel)**: a `ComboBox` over `[...taskChecks as {id: 'c' + id, name}, ...taskChecklists as {id: 'l' + id, name: 'Checklist: ' + title}]` with `create-label="Eigen check"`. Picking adds a pill and `@create` adds a free-title pill. Clicking a pill removes it.
- Payload: `checks_per_unit`, `extra_check_ids`, `extra_checklist_ids`, `extra_titles`. The edit drawer pre-fills the pills from the instance's distinct `is_extra` sources and always sends all three keys.

- [ ] **Step 1:** Implement both drawers and the ShowPage props.
- [ ] **Step 2:** Browser: add a task from a template with checks, aantal 3, per machine on, plus one library check and one free title. Expected: the drawer shows 3 units, each with the template checks plus both extras. Edit it, remove the free title and save. Expected: the title is gone from all 3 units. Tick one check, edit again, and flip the switch. Expected: the switch is disabled with the error text.
- [ ] **Step 3:** Run eslint on every file from this task and show the diff.

---

### Task 12: Editor backend

**Files:**
- Create:
  - Controllers: `app/Http/Controllers/ServiceOrderTaskCheckController.php` (`index`, `store`, `update`, `destroy`), `ServiceOrderTaskChecklistController.php` (`store`, `update`, `destroy`), `ServiceOrderTaskCheckLinkController.php` (`store`, `update`, `destroy`, `reorder`), `ServiceOrderTaskChecklistLinkController.php` (`store`, `update`, `destroy`, `reorder`).
  - Policies: `app/Policies/ServiceOrderTaskCheckPolicy.php` (`read(User)` uses `serviceordertask.read`; `manage(User)` uses `serviceordertask.update`), `app/Policies/ServiceOrderTaskChecklistPolicy.php` (the same two methods).
  - Requests in `app/Http/Requests/`: `ServiceOrderTaskCheckReadRequest`, `ServiceOrderTaskCheckStoreUpdateRequest`, `ServiceOrderTaskCheckDeleteRequest`, `ServiceOrderTaskChecklistStoreUpdateRequest`, `ServiceOrderTaskChecklistDeleteRequest`, `ServiceOrderTaskCheckLinkStoreRequest`, `ServiceOrderTaskCheckLinkUpdateRequest`, `ServiceOrderTaskCheckLinkDeleteRequest`, `ServiceOrderTaskChecklistLinkStoreRequest`, `ServiceOrderTaskChecklistLinkUpdateRequest`, `ServiceOrderTaskChecklistLinkDeleteRequest`, `ServiceOrderTaskLinkReorderRequest`. Each authorizes with `can('read'|'manage', ServiceOrderTaskCheck::class)`.
- Modify: `routes/web.php`, next to `serviceordertasks`.

**Interfaces:**
- `GET /serviceordertaskchecks` renders `ServiceOrderTaskChecks/EditorPage` with:
  - `checks: [{id, title}]` (by title);
  - `checklists: [{id, title, links: [{id, check_id, is_required, sort_order}]}]`;
  - `tasks: [{id, title, check_links: [{id, check_id, is_required, sort_order}], checklist_links: [{id, checklist_id, is_required, sort_order}]}]`.
- Checks and checklists: `POST`/`PATCH`/`DELETE /serviceordertaskchecks[/{serviceordertaskcheck}]` and `/serviceordertaskchecklists[/{serviceordertaskchecklist}]`. Rule `title` `required|string|max:255`.
- Check links: `POST /serviceordertaskchecklinks` with `{check_id, target: 'task'|'checklist', target_id}`.
  - The server maps `target` to the FQCN and validates that `target_id` exists.
  - A duplicate is refused with an error on `check_id`: `Deze check zit hier al in.`
  - A new link gets `is_required` true and `sort_order` max + 1.
- `PATCH /serviceordertaskchecklinks/{link}` takes `{is_required}`; `DELETE` removes the link.
- `POST /serviceordertaskchecklinks/reorder` takes `{ids: int[]}`. All ids must share one checkable; otherwise it's refused with an error on `ids`.
- Checklist links: the same set at `/serviceordertaskchecklistlinks`, with `{checklist_id, task_id}`. A duplicate is refused with: `Deze checklist zit al in deze taak.`
- Every write responds `redirect()->back()` (Inertia, `preserveState` on the client).
- Deleting a check or checklist removes its links (cascade); werkbon rows keep their copies (null on delete).

- [ ] **Step 1:** Implement the routes, policies, requests and controllers.
- [ ] **Step 2:** `APP_ENV=local php artisan route:list --path=serviceordertaskcheck`. Expected: all routes listed under `auth`.
- [ ] **Step 3 (Review Focus 2):** Scratchpad script (rolled back): seed a werkbon task from template T, then remove check A from checklist L and delete check C from the library. Expected: the instance still has rows titled A and C (C with `service_order_task_check_id` null), and `TaskInstanceCompletionGuard::problem()` still returns the checks message while the required A or C is open.
- [ ] **Step 4:** Write `tests/Feature/TaskChecks/EditorLinksTest.php`:
  - `test_linking_a_check_twice_is_refused`
  - `test_the_target_name_maps_to_the_model` (`target: 'checklist'` stores the checklist FQCN; `target: 'App\\Models\\User'` fails validation)
  - `test_reorder_refuses_links_of_different_owners`
  - `test_deleting_a_check_keeps_the_werkbon_copies`
  - `test_the_editor_needs_the_task_permissions` (no `serviceordertask.update` → 403 on a write)
- [ ] **Step 5:** Run the TaskChecks tests under flock. Expected: all pass.
- [ ] **Step 6:** Run pint on every file from this task and show the diff.

---

### Task 13: Editor page: columns and cards

**Files:**
- Create: `resources/js/Pages/ServiceOrderTaskChecks/EditorPage.vue`, `resources/js/Components/TaskChecks/EditorColumn.vue`, `resources/js/Components/TaskChecks/EditorCard.vue`
- Modify: `resources/js/Navigation/menu.json`. Add `{ "id": "serviceordertaskchecks", "label": "Checklists", "href": "/serviceordertaskchecks", "permission": "serviceordertask.read" }` under Werkbonnen, directly below Taken. The page header reads **Checklists voor werkbontaken**, subtitle `Stel per werkbontaak in welke checks de monteur afvinkt.`

**Interfaces:**
- `EditorColumn` props: `title`, `count`, `addLabel` (null hides the add input), `searchPlaceholder`. Emits `add(title)`. Default slot for the cards. It scrolls independently (`overflow-y-auto`) and exposes its scroll element through `defineExpose({ scroller })`.
- `EditorCard` props: `kind: 'check'|'checklist'|'task'`, `title`, `usage: number`, `selected`, `faded`, `open`, `editable`.
  - Emits `select`, `rename(title)`, `delete`, `toggle-open`.
  - Root element has `data-card="{kind}:{id}"`.
  - Colours follow the mockup: check slate, checklist green, task blue.
  - Rename uses `EditableTextField`. Delete sits in a small menu and asks `Verwijderen? Zit in {usage} taken.` (or `in geen enkele taak`) through `ModalDialog`.
  - Task cards aren't editable here.
- Page: three columns titled `Checks`, `Checklists` and `Taken` with **+ Nieuwe check** and **+ Nieuwe checklist**. Cards are alphabetical. Search filters per column. Below `md` the page shows only `Open deze pagina op een groter scherm om checklists te beheren.`

- [ ] **Step 1:** Implement the page, column, card and menu entry.
- [ ] **Step 2:** Browser at 1400×900: create check "Rooster schoongemaakt" and checklist "Checklist oplevering", rename the check, then delete it. Expected: each action shows at once, column scroll positions stay put, and the confirmation names the usage. At 390 wide: the notice shows instead.
- [ ] **Step 3:** Run eslint on every file from this task and show the diff.

---

### Task 14: Lines, drag-to-link, line popover, focus

**Files:**
- Create: `resources/js/Components/TaskChecks/EditorLines.vue`, `resources/js/Components/TaskChecks/LinkPopover.vue`, `resources/js/Composables/useCardAnchors.js`
- Modify: `resources/js/Pages/ServiceOrderTaskChecks/EditorPage.vue`, `resources/js/Components/TaskChecks/EditorCard.vue`

**Interfaces:**
- `useCardAnchors(container: Ref<HTMLElement>, scrollers: Ref<HTMLElement[]>)` returns `{ anchors: Ref<Map<string, {left: {x, y}, right: {x, y}, visible: boolean}>>, refresh() }`.
  - Positions are relative to the container.
  - It recomputes on `ResizeObserver`, on each scroller's `scroll` (rAF-throttled), and after `nextTick` when props change.
  - A card scrolled out of its column clamps its anchor y to the column's top or bottom edge, with `visible: false`.
- `EditorLines` props: `links: Array<{key: string, from: string, to: string, required: boolean}>`, `anchors`, `selectedKey: string|null` (a card key), `dragLine: {from: string, x: number, y: number}|null`.
  - Each link draws a cubic bezier `M fx fy C fx+dx fy, tx-dx ty, tx ty` with `dx = (tx - fx) / 2`: 2px wide, dashed `5 4` when optional, blue when touching the selected card, faded (`opacity .3`) when another card is selected.
  - On top of each line sits a transparent 14px `stroke` hit path with `pointer-events: stroke` that emits `line-click(key, {x, y})`.
- Drag (native HTML5): a check card drags onto a checklist or task card; a checklist card drags onto a task card.
  - While dragging, `dragLine` follows `dragover` coordinates.
  - Valid targets highlight; invalid ones get `cursor: not-allowed`.
  - Dropping posts to Task 12's store routes with `preserveState: true, preserveScroll: true`.
- `LinkPopover` props: `link`, `position`. It shows a two-button toggle **Verplicht / Optioneel** (clicking the active one does nothing; there are only two states) and **Ontkoppelen**. On a checklist → task link it also says `Optioneel maakt de hele checklist optioneel voor deze taak.`
- Clicking a card sets `selectedKey`; clicking the selected card clears it. Connected cards stay full, the rest get `faded`.

- [ ] **Step 1:** Implement the anchors composable, lines, popover, drag and focus.
- [ ] **Step 2:** Browser: drag "Rooster schoongemaakt" onto "Checklist oplevering". Expected: a solid line appears. Drag the checklist onto two tasks. Click one line and choose **Optioneel**. Expected: the line becomes dashed. Click a task card. Expected: only its lines and cards stay bright, and clicking it again restores all. Scroll the checks column. Expected: the lines follow and clamp at the column edge. Reload. Expected: everything persists.
- [ ] **Step 3:** Run eslint on every file from this task and show the diff.

---

### Task 15: Ordering inside an opened card

**Files:**
- Create: `resources/js/Components/TaskChecks/CardContents.vue`
- Modify: `resources/js/Components/TaskChecks/EditorCard.vue`, `resources/js/Pages/ServiceOrderTaskChecks/EditorPage.vue`

**Interfaces:**
- `CardContents` props: `kind: 'checklist'|'task'`, `items: Array<{linkId: number, kind: 'check'|'checklist', title: string, is_required: boolean}>`.
  - It renders a `VueDraggableNext` list with a grip and a `verplicht` / `optioneel` pill per row. Clicking a pill toggles it through the link update route.
  - On a task, its checklists and its loose checks are two separate draggable lists, checklists first.
  - Drop end posts `reorder` with the new id order.
- `EditorCard` shows `CardContents` when `open`; the `toggle-open` emit toggles it. Lines re-measure after it opens or closes.

- [ ] **Step 1:** Implement the contents component and the open state.
- [ ] **Step 2:** Browser: open "Checklist oplevering", drag the third check to the top and reload. Expected: the new order persists. Create a werkbon task from a template with that checklist. Expected: its checks appear in the new order.
- [ ] **Step 3:** Run eslint on every file from this task and show the diff.

---

### Task 16: Manual and final pass

**Files:**
- Modify: `docs/guide/handleiding.md`:
  - **Taken op een werkbon** (line ~190): checks, required, per machine or whole group, extras, completion blocked while required checks are open, locks after ticking.
  - **De werkbonpagina**, monteur part (line ~158-164): the box folds open via **Uitvoeren**, with serials and checks, and **Taak voltooien**.
  - A new section **Checklists beheren** under Werkbonnen: the three columns, dragging, solid vs dashed lines, clicking a line, clicking a card, opening a card to reorder, deleting.

- [ ] **Step 1:** Update the three manual sections in Dutch, in the manual's existing style.
- [ ] **Step 2:** Run the full suite once, under flock: `flock /tmp/claude-1000/-home-guido-nvme0n1p1-code-lavoro/test-db.lock php artisan test`. Expected: everything passes; any failure outside `TaskChecks` is checked against `master` before it is blamed on this work.
- [ ] **Step 3:** Walk through it in the browser end to end:
  - build a checklist in the editor and attach it to a task;
  - add that task to demo werkbon 197 per machine;
  - in the monteur view on a 390-wide viewport, fill the serials, tick the checks and complete the task;
  - the timeline shows the ticks.

  Afterwards, roll the demo data back with a script.
- [ ] **Step 4:** Show the full diff (`git diff --stat` plus untracked files) and wait for approval before any commit.
