<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        $roleIds = $data['role_ids'] ?? [];
        $user->roles()->sync($roleIds);

        $avatar = request()->file('avatar');
        if ($avatar instanceof UploadedFile) {
            $dirname = 'users/' . $user->id . '/avatar';
            Storage::disk('public')->makeDirectory($dirname);
            $avatar->storeAs($dirname, $avatar->getClientOriginalName(), 'public');
        }

        return redirect()->route('users.index')->with('success', 'Gebruiker aangemaakt');
    }

    public function update(UserUpdateRequest $request, User $user)
    {
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $roleIds = $data['role_ids'] ?? [];
        $user->roles()->sync($roleIds);
        $avatar = request()->file('avatar');
        if ($avatar instanceof UploadedFile) {
            $dirname = 'users/' . $user->id . '/avatar';
            Storage::disk('public')->deleteDirectory($dirname);
            Storage::disk('public')->makeDirectory($dirname);
            $avatar->storeAs($dirname, $avatar->getClientOriginalName(), 'public');
        }
        return redirect()->route('users.index')->with('success', 'Gebruiker bijgewerkt');
    }
}
