<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Services\UserAvatarService;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all();

        return inertia('Users/IndexPage', [
            'users' => $users,
        ]);
    }


    public function create()
    {
        return inertia('Users/EditPage', [
            'user' => null,
            'allRoles' => Role::orderBy('name')->get(['id','name']),
        ]);
    }

    public function edit(User $user)
    {
        $user->load('roles:id,name');
        return inertia('Users/EditPage', [
            'user' => $user,
            'allRoles' => Role::orderBy('name')->get(['id','name']),
        ]);
    }

    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        unset($data['avatar']);
        $user = User::create($data);
        $role_ids = $data['role_ids'] ?? [];
        $user->roles()->sync($role_ids);

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
        $role_ids = $data['role_ids'] ?? [];
        $user->roles()->sync($role_ids);
        app(UserAvatarService::class)->save($user, request()->file('avatar'));
        return redirect()->route('users.index')->with('success', 'Gebruiker bijgewerkt');
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
            'allRoles' => $user->isAdmin() ? Role::orderBy('name')->get(['id','name']) : [],
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
