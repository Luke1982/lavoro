# Checks on werkbontaken — design

Date: 2026-11-13. Status: approved.

Calls marked **(my call)** were made while writing this and have not been confirmed by the product owner. Everything else was asked for.

## Intent

A werkbontaak ("Airco installatie, 3× Daikin 3MXM52A") today is one checkbox plus serial numbers. The company wants to say *how* a task is done: a list of things the mechanic ticks off on site ("Leidingen afgeperst", "Rooster schoongemaakt"), some of them mandatory. The mechanic works through them in the monteur view, in the task's own box, without a drawer, the way the marketing animation shows (https://lavorofsm.nl/wp-content/themes/lavoro/assets/animations/serviceorder-animation.html). In that animation the box folds open into a checklist with a time per tick and a serial field with a scan button and a "Geregistreerd" badge. The customer's signature at the bottom of the animation is not part of this project (see Non-goals). The three editor layouts from the brainstorm are in `2026-11-13-serviceorder-task-checks-editor-mockups.html` next to this file; B was chosen.

This is pre-work for two later projects. The afsluiting (the walk-through with the customer) will read "this is what we did" from these checks. Werkbon-acties will create tasks with their checklists in one click.

## Goals

- A library of **checks**: a title, nothing more. One check can be used by many tasks ("Rooster schoongemaakt" is generic).
- A library of **checklists**: a reusable, ordered set of checks ("Checklist oplevering", 4 checks). Change a checklist once and every task that uses it follows, for werkbonnen created from then on.
- A task template (`ServiceOrderTask`) gets checks directly and/or whole checklists. Each link says whether the check is **required**.
- Creating a task on a werkbon copies the template's checks onto it.
- When creating a task on a werkbon, the user can add **extra, optional** checks.
- When the task covers more than one unit, the user picks: checks **per machine** or **once for the whole group**.
- A task cannot be completed while a required check is open.
- In the monteur view, tapping a task folds its box open: serial input(s) and checks together, per machine or as one group, with a **Taak voltooien** button at the bottom. No drawer.
- An admin editor in three columns (Checks | Checklists | Taken) where links are made by dragging and shown as lines between cards (mockup B from the brainstorm).

## Non-goals

- Checks with a value (a measured pressure, a photo, a text). Done or not done only.
- Checks on keuringen or storingen. The link is built so they could later be attached to something other than a task, but nothing else uses it now.
- Showing checks in the current Afronding modal. The afsluiting redesign picks that up. (The werkbon PDF was added after the build, on request: under each task, the ticked checks with their time, per machine or for the whole task; unticked checks are left off.)
- Changing werkbonnen that already exist when a template, checklist or check changes.
- An "eenmalig / per machine" setting per check. The choice is made once per werkbon task. With per machine on, "Uitleg gegeven aan klant" is asked once per machine; that was accepted knowingly.
- Using the editor on a phone. Templates are managed at a desk. **(my call)**
- A customer signature in the fold-open box. That is a separate project. The box covers checks and serials. Signing a single task stays as it is (standaard view, after completion).
- New assistant tools. That is a separate project. No assistant tool reads a werkbon's tasks or completes one today, and this project adds none. Until then the assistant learns about ticks through the activity trail (`SearchActivityTool`), which the check signals feed.
- New tests, unless asked (`CLAUDE.md`).

## Anti-goals: failures even if everything technically works

- A task completed while a required check is open, by any path: the checkbox, **Opslaan en voltooien** in the serial drawer, or the fold-open box. A task created with `is_complete` already set (the store request accepts it) must not slip past the rule either: a task that gets required checks cannot be created as complete.
- Editing a checklist that silently changes a werkbon a mechanic is working on.
- Ticking a check that reloads the page, collapses the open box or jumps the scroll on the mechanic's phone.
- Per-machine checks that move to a different machine when a serial is corrected or a machine is replaced.
- A tick that is missing from the timeline, so the assistant truthfully says it does not know who did it.
- A task without checks that behaves differently from today. A plain task is still one tap to tick off.

## Constraints

- Multi-tenancy: all new tables are tenant migrations (`database/migrations/tenant/`).
- Morph columns keep the FQCN (no morph map). New pivot tables follow the `-ables` naming.
- Authorization in Form Request `authorize()` through a policy `can()`; validation only in `rules()`; the frontend only shows `form.errors`.
- A refusal a person can act on ("vink eerst de verplichte checks af") throws `App\Exceptions\Refusal`.
- Facts with a name ("check afgevinkt") are signals raised through `Signals::dispatch()`, with `activityDescription()`, `changes()` and an `activityContext()` that also puts them on the customer's timeline, like the existing `app/Domain/Signals/Tasks/*`.
- Two paths create task instances today: `ServiceOrderTaskInstanceController::store` and `AddServiceOrderTaskTool`. Both must go through one shared step that copies the checks.
- Selecting toggles: clicking a selected card deselects it, with no separate clear button.
- `docs/guide/handleiding.md` is updated in the same change (Taken, monteurweergave, a section for the new editor).

## Approaches

**Storage.** Two were considered:

- **A (chosen):** checks link to a task *or* a checklist through one morph pivot; checklists link to tasks through a second morph pivot. A check can sit loose on a task, inside a checklist, or both, and the morph leaves room to hang checks on other things later.
- **B:** checks only ever live in checklists; a task's own checks become a hidden checklist that belongs to that task alone. That's one link fewer, but hidden checklists have to be filtered out of every picker, and there's no general link. Rejected.

**Werkbon side.** The werkbon copies (snapshots) the checks at creation rather than pointing at the templates. A running werkbon must not change underneath the mechanic, and the afsluiting needs to show exactly what was ticked even after a check is renamed or deleted.

**Editor layout.** Three were mocked:

- **A, free canvas:** turns into a tangle at 30 tasks and 100 checks. Rejected.
- **B, three columns (chosen):** lines always run left to right, and clicking a card fades everything that isn't connected to it.
- **C, one task at a time:** calm, but you can't see where a check is used. Rejected. Its open-list card returns inside B for ordering.

**Drawing the lines: DOM cards with an SVG overlay, not Konva (confirmed by the product owner after checking with the annotation session and Konva's Connected Objects example).** The user asked to share the Konva work being done for image annotation. That session uses plain `konva` 10.9.1 in one component (`resources/js/Components/Images/ImageAnnotator.vue`), built around a single photo, with no composable or wrapper to share, and it is not committed. Konva has no node editor of its own; it is a canvas drawing library. Drawing the cards in Konva would mean rebuilding inline rename, search, scrolling, dark mode and Tailwind styling on a canvas. Drawing only the lines in Konva would add a second coordinate system to keep in sync with three scrolling DOM columns. An SVG `<path>` with a wide transparent stroke gives click hit-testing for free and scrolls and styles with the cards. No new dependency: reordering inside an open card uses `vue-draggable-next`, which is already installed.

## Data model

```
service_order_task_checks            id, title, timestamps
service_order_task_checklists        id, title, timestamps

service_order_task_checkables        id, service_order_task_check_id,
                                     checkable_type, checkable_id,      -- ServiceOrderTask | ServiceOrderTaskChecklist
                                     is_required (bool), sort_order, timestamps
                                     unique (check, checkable_type, checkable_id)

service_order_task_checklistables    id, service_order_task_checklist_id,
                                     checklistable_type, checklistable_id,  -- ServiceOrderTask
                                     is_required (bool, default true), sort_order, timestamps
                                     unique (checklist, checklistable_type, checklistable_id)

service_order_task_instances         + checks_per_unit (bool, default false)

service_order_task_instance_checks   id, service_order_task_instance_id (cascade),
                                     service_order_task_check_id (nullable, null on delete),
                                     service_order_task_checklist_id (nullable, null on delete),
                                     title, checklist_title (nullable),
                                     is_required, is_extra, is_done,
                                     unit_index (nullable: null = for the whole task),
                                     asset_id (nullable, null on delete),
                                     sort_order, checked_at, checked_by (users, nullable), timestamps
```

- **Required lives on the link**, not on the check: "Rooster schoongemaakt" can be required in Onderhoud and optional in Storing verhelpen. A checklist decides which of its own checks are required.
- **The link between a checklist and a task carries its own properties too**, starting with required/optional. Required means the checklist's own flags apply. Optional makes every check in it optional for that task: "Checklist oplevering" can be mandatory on Airco installatie and a guide on Storing verhelpen. The link is a row of its own (`service_order_task_checklistables`), so later properties go there as well.
- A copied check is required when its own link says so **and**, if it came through a checklist, that checklist's link to the task does too.
- `title` and `checklist_title` are copies, so renaming or deleting a template never rewrites a werkbon. The ids are kept to group and trace, never to display.
- `checked_at` / `checked_by` are saved because the animation shows the time each check was ticked, and the timeline needs who. **(my call)**

## Behaviour

### What a task copies at creation

- A task's checklists first, in their order, each checklist's checks in their order with their required flag. Then the task's loose checks, in their order. **(my call)**
- A check that appears more than once (loose and in a checklist, or in two checklists) is copied once: under the first checklist it appears in, required if it is required through any of those routes (see Data model). **(my call)**
- Extras added in the create form are always optional (`is_extra = true`). They can be picked from the library (a check or a whole checklist) or typed as a free title that stays on this werkbon only and is not added to the library. **(my call:** a mechanic's one-off line should not end up in the company's library.)
- A task without a template (free description) has no copied checks, only extras.

### Units: per machine or for the whole group

- A **unit** is one sold unit: 3× Daikin is 3 units; 2 bundles of "1 omvormer + 3 panelen" is 2 units, not 8. **(my call)**
- The choice **Per machine / Voor de hele groep** only shows when the task has more than one unit. Default: for the whole group. **(my call)**
- Per machine: the whole set is copied once per unit (`unit_index` 0…n-1). For the whole group: one set, `unit_index` null.
- When a unit's machine is registered, its checks get that machine's `asset_id` (for a bundle, the bundle machine). From then on the checks follow the machine, not the position, so correcting a serial or replacing a machine does not move them. Until then the unit is addressed by its index.
- Quantity raised: new units get the same set, unticked. Quantity lowered: units are removed only if they have no registered machine and no ticked check, highest index first. If there aren't enough such units, the change is refused as a validation error on the aantal naming the unit ("Bij machine 3 zijn al checks afgevinkt; kies een hoger aantal."), the same way the existing check refuses to strand registered machines. Ticked work is never deleted, matching today's refusal to strand registered machines. Labels number the remaining units by position (1/2, 2/2), so gaps in `unit_index` are never shown. The same applies when the product is changed or removed. **(my call)**
- Switching between per machine and the whole group is allowed while no check on the task is ticked; the checks are then rebuilt. After the first tick it is locked: going from three sets to one would mean choosing whose ticks count, and from one to three whether a single tick counts for every machine. Neither can be decided automatically.

### Ticking and completing

- Ticking sets `is_done`, `checked_at` and `checked_by`. Unticking clears them. Both are refused once the task is complete or cancelled, or the werkbon is closed.
- The UI updates instantly and saves in the background. A failed save reverts the tick and shows the message. The open box never collapses or reloads.
- Completing the task is refused while any required check, on any unit, is open: "Vink eerst alle verplichte checks af." This sits next to the existing "all machines registered" condition in `ServiceOrderTaskInstanceController::toggle`, in one place that every completion path calls.
- Reopening a task (where allowed today) keeps the ticks.
- Extras can be added and removed in the edit drawer while the task is not complete. Copied checks cannot be removed from a werkbon task; otherwise "required" means nothing. **(my call)**
- A signal per tick/untick (for example `TaskCheckChanged`, "Check "Rooster schoongemaakt" afgevinkt bij taak "Airco installatie", machine 2 (DK-2231-A)"), with the service order as subject and the customer in its context, following `TaskCompletionChanged`. The model-level trail must not log the same change a second time.

### Monteur view: the box folds open

- Tapping a task with checks or machines folds its row open in place, animated like the marketing animation. Tapping the row header again folds it shut. A task with neither stays a single tap to complete.
- Inside, per machine: the unit's label ("Daikin 3MXM52A · 2/3"), its serial input (the existing slot logic, including scan and placeholder serials), then its checks with the time each was ticked. For the whole group: all serial inputs first, then one checklist. Checklist titles appear as small headings. Required checks carry a marker.
- A progress count in the collapsed row ("4/9").
- **Taak voltooien** at the bottom, enabled when every slot is filled and every required check is ticked. If it's disabled, the box says what's missing.
- The contents are one component. The monteur view shows it inline; the standaard view shows the same component inside its existing "Machines registreren" drawer (renamed to fit, since it now holds checks too), so the two views never drift apart.

### Standaard view

- The task row shows the check progress next to its status.
- The **Taak toevoegen** and **Taak bewerken** drawers get the per-machine choice (only with more than one unit) and the extras picker.

### The editor (Checks | Checklists | Taken)

- A new page under Werkbonnen next to Taken. Reading needs `serviceordertask.read` and changing needs `serviceordertask.update`, so no new permissions. **(my call)**
- Three columns, each with its own search and scroll. Cards are alphabetical.
- **+ Nieuwe check** and **+ Nieuwe checklist** at the top of their columns create a card inline. Tasks are still created on the Taken page.
- Linking: drag a check onto a checklist or task card, or a checklist onto a task card. A new link is **required** by default. **(my call:** you attach a check on purpose.)
- A line between linked cards: solid for required, dashed for optional. This holds for every line, including checklist → task. Clicking a line opens a small popover with **Verplicht / Optioneel** and **Ontkoppelen**. On a checklist → task line, Optioneel makes the whole checklist optional for that task, and the popover says so.
- Clicking a card highlights only its lines and connected cards; clicking it again deselects it.
- A checklist or task card can open in place to show its contents in order: checks with their required pill, draggable to reorder. On a task, its checklists and its loose checks each keep their own order, checklists first.
- Rename inline on the card. Delete from the card's menu with a confirmation that says where it is used ("zit in 4 taken"). Werkbonnen keep their copies.
- A line to a card scrolled out of view ends at the edge of its column.
- Below the `md` breakpoint the page says to use a larger screen.

### Assistant

- `AddServiceOrderTaskTool` creates tasks through the same shared step, so its tasks get their checks.

## Left to the builder

- Endpoint shapes for ticking (it must not trigger a full Inertia page reload of the werkbon; see the memory note on JSON requests and redirects).
- Signal keys and names, component names, and file layout.
- How the line positions are measured and kept in sync with scrolling and resizing.
- Whether the template models use `RecordsHistory`. `ServiceOrderTask` doesn't today.
