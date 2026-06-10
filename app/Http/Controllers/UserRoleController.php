<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRoleDeleteRequest;
use App\Http\Requests\UserRoleReadRequest;
use App\Http\Requests\UserRoleStoreUpdateRequest;
use App\Models\UserRole;

class UserRoleController extends Controller
{
    public function index(UserRoleReadRequest $request)
    {
        return inertia('UserRoles/IndexPage', [
            'roles' => UserRole::orderBy('name')->get(),
        ]);
    }

    public function store(UserRoleStoreUpdateRequest $request)
    {
        UserRole::create($request->validated());

        return redirect()->back()->with('success', 'Gebruikersrol is aangemaakt');
    }

    public function update(UserRoleStoreUpdateRequest $request, UserRole $userrole)
    {
        $userrole->update($request->validated());

        return redirect()->back()->with('success', 'Gebruikersrol is bijgewerkt');
    }

    public function destroy(UserRoleDeleteRequest $request, UserRole $userrole)
    {
        $userrole->delete();

        return redirect()->back()->with('success', 'Gebruikersrol is verwijderd');
    }
}
