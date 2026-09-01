<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserDeleteRequest;
use App\Http\Requests\UserRestoreRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Central\Package;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAvatarService;

class UserController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->can('viewAny', User::class), 403);
        $users = User::all();
        $deleted_users = auth()->user()->can('viewTrashed', User::class)
            ? User::onlyTrashed()->get()
            : collect();

        return inertia('Users/IndexPage', [
            'users' => $users,
            'deletedUsers' => $deleted_users,
        ]);
    }

    public function create()
    {
        abort_unless(auth()->user()->can('create', User::class), 403);

        return inertia('Users/EditPage', [
            'user' => null,
            'allRoles' => $this->assignableRoles(),
            'seats' => $this->seats(),
        ]);
    }

    public function edit(User $user)
    {
        abort_unless(auth()->user()->can('view', $user), 403);
        $user->load('roles:id,name');

        return inertia('Users/EditPage', [
            'user' => $user,
            'allRoles' => $this->assignableRoles(),
            'seats' => $this->seats(),
            'unavailabilities' => $user->unavailabilities()
                ->orderBy('type')
                ->orderBy('day_of_week')
                ->orderBy('date')
                ->get(),
        ]);
    }

    /**
     * Hoeveel plaatsen er per soort zijn en hoeveel er nog vrij zijn. Het
     * formulier laat dat zien; de validatie bewaakt het.
     */
    private function seats(): array
    {
        if (!tenancy()->initialized) {
            return [];
        }

        $tenant = tenancy()->tenant;
        $package = Package::on('central')->where('key', $tenant->package_key)->first();

        return collect([
            'field' => ['label' => 'Buitendienst', 'limit' => (int) ($package->field_seats ?? 0) + (int) $tenant->extra_field_seats],
            'office' => ['label' => 'Binnendienst', 'limit' => (int) ($package->office_seats ?? 0) + (int) $tenant->extra_office_seats],
        ])->map(fn ($seat, $type) => $seat + ['used' => User::where('seat_type', $type)->count()])->all();
    }

    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        $role_ids = $data['role_ids'] ?? null;
        unset($data['avatar'], $data['role_ids']);
        $user = User::create($data);
        if ($role_ids !== null) {
            $user->roles()->sync($role_ids);
        }

        app(UserAvatarService::class)->save($user, request()->file('avatar'));

        return redirect()->route('users.index')->with('success', 'Gebruiker aangemaakt');
    }

    public function update(UserUpdateRequest $request, User $user)
    {
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids']);
        }
        app(UserAvatarService::class)->save($user, request()->file('avatar'));

        return redirect()->route('users.index')->with('success', 'Gebruiker bijgewerkt');
    }

    public function destroy(UserDeleteRequest $request, User $user)
    {
        $user->delete();

        return redirect()->route('users.index')->with('success', 'Gebruiker verwijderd');
    }

    public function restore(UserRestoreRequest $request, User $user)
    {
        $user->restore();

        return redirect()->route('users.index')->with('success', 'Gebruiker hersteld');
    }

    /**
     * Roles the current user may hand out; empty when they may not.
     */
    private function assignableRoles()
    {
        return auth()->user()?->can('assignRoles', User::class)
            ? Role::assignable()->orderBy('name')->get(['id', 'name'])
            : [];
    }

    /**
     * Edit the currently authenticated user's profile (non-admins allowed).
     */
    public function editSelf()
    {
        $user = request()->user();
        abort_unless($user, 403);
        $user->load('roles:id,name');

        return inertia('Users/EditPage', [
            'user' => $user,
            'allRoles' => $this->assignableRoles(),
            'seats' => $this->seats(),
            'unavailabilities' => $user->unavailabilities()
                ->orderBy('type')
                ->orderBy('day_of_week')
                ->orderBy('date')
                ->get(),
        ]);
    }

    /**
     * Update the currently authenticated user's profile (non-admins allowed).
     */
    public function updateSelf(UserUpdateRequest $request)
    {
        $user = request()->user();
        abort_unless($user, 403);

        $data = $request->validated();
        unset($data['role_ids']);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        app(UserAvatarService::class)->save($user, request()->file('avatar'));

        return redirect()->route('me.edit')->with('success', 'Profiel bijgewerkt');
    }
}
