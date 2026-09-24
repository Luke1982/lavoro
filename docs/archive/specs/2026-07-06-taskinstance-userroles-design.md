# ServiceOrderTaskInstance UserRole visibility

## Problem

A `ServiceOrderTaskInstance` should be assignable to one or more `UserRole`s (e.g. "Elektricien", "Loodgieter"). When an executing user views a `ServiceOrder` (via the event(s) that plan it), they should only see task instances that either:

- have no `UserRole`s assigned, or
- have at least one `UserRole` that matches a role this user currently holds as an executing user on one of the events planning this service order.

Admins always see every task instance, unfiltered.

## Data model

New generic polymorphic pivot table, following the project's existing `-ables` convention (`imageables`, `documentables`, `userables`, etc.):

```php
Schema::create('userroleables', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_role_id')->constrained()->cascadeOnDelete();
    $table->morphs('userroleable'); // userroleable_id, userroleable_type
    $table->timestamps();
    $table->unique(['user_role_id', 'userroleable_id', 'userroleable_type'], 'userroleables_unique');
});
```

`ServiceOrderTaskInstance` gets the owning-side relation (mirrors how `Asset::images()` etc. only define the relation on the owning model, not on `Image`):

```php
public function userRoles()
{
    return $this->morphToMany(UserRole::class, 'userroleable')->withTimestamps();
}
```

`UserRole` gets no inverse relation — not needed by any current consumer.

This table is intentionally separate from the existing `user_role_userable` pivot. That pivot is keyed to a specific `userables` row (i.e. one user's executing assignment on one event) and answers "which roles does this user hold on this event". `userroleables` answers a different question — "which roles does this task require" — with no user or event involved. They are combined only at query time when computing visibility.

## Visibility computation

Add a helper to the existing `HasExecutingUsers` trait (used by `Event`, `ServiceOrder`, `ServiceJob`), mirroring the existing `syncExecutingUserRoles`:

```php
public function executingUserRoleIds(int $user_id): array
{
    $userable_id = DB::table('userables')
        ->where('userable_type', $this->getMorphClass())
        ->where('userable_id', $this->getKey())
        ->where('type', 'executing')
        ->where('user_id', $user_id)
        ->value('id');

    if (! $userable_id) {
        return [];
    }

    return DB::table('user_role_userable')
        ->where('userable_id', $userable_id)
        ->pluck('user_role_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}
```

In `ServiceOrderController::show()`, after loading `$service_order` (which already eager-loads `events` and will additionally eager-load `taskInstances.userRoles`):

```php
$user = Auth::user();

if (! $user->isAdmin()) {
    $role_ids = $service_order->events
        ->flatMap(fn ($event) => $event->executingUserRoleIds($user->id))
        ->unique()
        ->values()
        ->all();

    $visible_instances = $service_order->taskInstances->filter(
        fn ($instance) => $instance->userRoles->isEmpty()
            || $instance->userRoles->pluck('id')->intersect($role_ids)->isNotEmpty()
    )->values();

    $service_order->setRelation('taskInstances', $visible_instances);
}
```

If the user is not an executing user on any event linked to the order, `$role_ids` is empty, so only roleless task instances remain visible. This applies uniformly — there's no separate "is this user even an executing user" branch; a non-executing, non-admin viewer simply resolves to an empty role set.

This filtering only affects what is rendered on the `ServiceOrder` show page. It does not restrict the create/update/toggle/etc. endpoints, which remain gated purely by existing permissions as today.

## Form Request / Controller changes

`ServiceOrderTaskInstanceStoreRequest` / `...UpdateRequest`: add

```php
'user_role_ids' => ['array'],
'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
```

`ServiceOrderTaskInstanceController::store()` / `update()`: after saving, sync roles:

```php
$instance->userRoles()->sync($request->input('user_role_ids', []));
```

## Frontend

`ServiceOrderController::show()` passes a new prop:

```php
'userRoles' => UserRole::orderBy('name')->get(['id', 'name', 'color']),
```

`ServiceOrders/ShowPage.vue` forwards it to `TaskInstancesWidget.vue` as a new `userRoles` prop, alongside the existing `availableTasks`/`products`.

`TaskInstancesWidget.vue`:

- **Add/edit drawers**: a pill-style toggle button group (same pattern as the day picker in `resources/js/Pages/Admin/GeneralSettingsPage.vue:24-37`), one button per `UserRole`, toggling membership in a `user_role_ids` array on the form. Active state: `bg-lavoro-blue border-lavoro-blue text-white`; inactive: `bg-white border-gray-300 text-gray-700 hover:bg-gray-50`. Submitted as `user_role_ids` alongside the existing fields.
- **Task list rows**: small colored badges next to the task title for each assigned `UserRole`, using that role's own `color` value (existing `UserRole.color` field), so admins/privileged viewers can see at a glance which roles a task requires.

## Out of scope

- No new permissions — visibility filtering is data-level, not permission-gated (admin vs. non-admin only).
- No changes to the `user_role_userable` per-event-per-user mechanism.
- No tests written unless requested, per project convention.
