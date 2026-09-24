# User Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seed `user.read`, `user.create`, `user.update`, `user.delete` permissions and wire them into Form Requests, UserController read actions, and the sidebar nav.

**Architecture:** A new migration seeds the four permissions following the existing `{resource}.{action}` pattern. `UserStoreRequest` and `UserUpdateRequest` get proper `authorize()` checks. `UserController` guards index/create/edit with `abort_unless`. The sidebar extracts the "Gebruikers" link from the admin-only block so non-admins with `user.read` can also see it.

**Tech Stack:** Laravel 12, Inertia, Vue 3

---

### Task 1: Seed user permissions

**Files:**
- Create: `database/migrations/2026_06_07_000002_seed_user_permissions.php`

- [ ] **Step 1: Create the migration file with this content**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            ['name' => 'user.read',   'label' => 'Gebruikers zien'],
            ['name' => 'user.create', 'label' => 'Gebruiker aanmaken'],
            ['name' => 'user.update', 'label' => 'Gebruiker bewerken'],
            ['name' => 'user.delete', 'label' => 'Gebruiker verwijderen'],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'user.read',
            'user.create',
            'user.update',
            'user.delete',
        ])->delete();
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: one migration runs, no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_07_000002_seed_user_permissions.php
git commit -m "feat(permissions): seed user CRUD permissions"
```

---

### Task 2: Authorize UserStoreRequest

**Files:**
- Modify: `app/Http/Requests/UserStoreRequest.php`

- [ ] **Step 1: Replace the `authorize()` method**

Current:
```php
public function authorize(): bool
{
    return true; // adjust authorization if needed
}
```

Replace with:
```php
public function authorize(): bool
{
    $user = $this->user();
    return $user && ($user->isAdmin() || $user->hasPermission('user.create'));
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/UserStoreRequest.php
git commit -m "feat(permissions): authorize UserStoreRequest with user.create"
```

---

### Task 3: Authorize UserUpdateRequest

**Files:**
- Modify: `app/Http/Requests/UserUpdateRequest.php`

- [ ] **Step 1: Replace the `authorize()` method**

Current:
```php
public function authorize(): bool
{
    return true;
}
```

Replace with:
```php
public function authorize(): bool
{
    $user = $this->user();
    return $user && ($user->isAdmin() || $user->hasPermission('user.update'));
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/UserUpdateRequest.php
git commit -m "feat(permissions): authorize UserUpdateRequest with user.update"
```

---

### Task 4: Gate read actions in UserController

**Files:**
- Modify: `app/Http/Controllers/UserController.php`

- [ ] **Step 1: Add `abort_unless` guards to `index()`, `create()`, and `edit()`**

Replace the three methods with:

```php
public function index()
{
    abort_unless(auth()->user()?->isAdmin() || auth()->user()?->hasPermission('user.read'), 403);
    $users = User::all();

    return inertia('Users/IndexPage', [
        'users' => $users,
    ]);
}

public function create()
{
    abort_unless(auth()->user()?->isAdmin() || auth()->user()?->hasPermission('user.create'), 403);
    return inertia('Users/EditPage', [
        'user'     => null,
        'allRoles' => Role::orderBy('name')->get(['id', 'name']),
    ]);
}

public function edit(User $user)
{
    abort_unless(auth()->user()?->isAdmin() || auth()->user()?->hasPermission('user.read'), 403);
    $user->load('roles:id,name');
    return inertia('Users/EditPage', [
        'user'             => $user,
        'allRoles'         => Role::orderBy('name')->get(['id', 'name']),
        'unavailabilities' => $user->unavailabilities()
            ->orderBy('type')
            ->orderBy('day_of_week')
            ->orderBy('date')
            ->get(),
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/UserController.php
git commit -m "feat(permissions): gate user index/edit with user.read, create with user.create"
```

---

### Task 5: Update sidebar in MainLayout.vue

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

`hasPermission` is already imported at the top of `<script setup>`. Add a computed and update both sidebar blocks.

- [ ] **Step 1: Add `canSeeUsers` computed in `<script setup>`**

After the `isTechnischBeheer` computed (line 387), add:

```js
const canSeeUsers = computed(() => isAdmin.value || hasPermission('user.read'))
```

- [ ] **Step 2: Update the mobile sidebar (around lines 101–134)**

The current mobile block looks like:
```html
<div class="px-6 mb-2 space-y-1" v-if="isAdmin">
    <img v-if="companyLogo" ...>
    <Link ... '/companies' ...> Bedrijf </Link>
    <Link ... '/users' ...> Gebruikers </Link>
    <Link ... '/roles' ...> Rollen </Link>
    <Link ... '/admin/calendar-grants' ...> Agenda-toegang </Link>
</div>
```

Split it into two `div` blocks — keep Bedrijf/Rollen/Agenda-toegang admin-only, extract Gebruikers:

```html
<div class="px-6 mb-2 space-y-1" v-if="isAdmin">
    <img v-if="companyLogo" class="h-8 w-auto ml-2 mb-2" :src="companyLogo"
        :alt="companyName || 'Bedrijf'" />
    <Link :href="'/companies'" @click="sidebarOpen = false" :class="[
        isCompanyCurrent ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <BuildingOffice2Icon class="size-6 shrink-0" />
        Bedrijf
    </Link>
    <Link @click="sidebarOpen = false" :href="'/roles'" :class="[
        currentPath.startsWith('/roles') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <UsersIcon class="size-6 shrink-0" />
        Rollen
    </Link>
    <Link @click="sidebarOpen = false" :href="'/admin/calendar-grants'" :class="[
        currentPath.startsWith('/admin/calendar-grants') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <CalendarIcon class="size-6 shrink-0" />
        Agenda-toegang
    </Link>
</div>
<div class="px-6 mb-2 space-y-1" v-if="canSeeUsers">
    <Link @click="sidebarOpen = false" :href="'/users'" :class="[
        currentPath.startsWith('/users') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <UsersIcon class="size-6 shrink-0" />
        Gebruikers
    </Link>
</div>
```

- [ ] **Step 3: Update the desktop sidebar (around lines 241–270)**

The current desktop block looks like:
```html
<div class="px-6 mb-2 space-y-1" v-if="isAdmin">
    <Link ... '/companies' ...> Bedrijf </Link>
    <Link ... '/users' ...> Gebruikers </Link>
    <Link ... '/roles' ...> Rollen </Link>
    <Link ... '/admin/calendar-grants' ...> Agenda-toegang </Link>
</div>
```

Split the same way (no `@click="sidebarOpen = false"` in desktop links):

```html
<div class="px-6 mb-2 space-y-1" v-if="isAdmin">
    <Link :href="'/companies'" :class="[
        isCompanyCurrent ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <BuildingOffice2Icon class="size-6 shrink-0" />
        Bedrijf
    </Link>
    <Link :href="'/roles'" :class="[
        currentPath.startsWith('/roles') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <UsersIcon class="size-6 shrink-0" />
        Rollen
    </Link>
    <Link :href="'/admin/calendar-grants'" :class="[
        currentPath.startsWith('/admin/calendar-grants') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <CalendarIcon class="size-6 shrink-0" />
        Agenda-toegang
    </Link>
</div>
<div class="px-6 mb-2 space-y-1" v-if="canSeeUsers">
    <Link :href="'/users'" :class="[
        currentPath.startsWith('/users') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
    ]">
        <UsersIcon class="size-6 shrink-0" />
        Gebruikers
    </Link>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/MainLayout.vue
git commit -m "feat(permissions): show Gebruikers nav link to users with user.read"
```
