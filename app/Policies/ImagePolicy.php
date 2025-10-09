<?php

namespace App\Policies;

use App\Models\Image;
use App\Models\User;
use Illuminate\Http\Request;

class ImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('image.see') || $user->hasPermission('image.upload');
    }

    public function view(User $user, Image $image): bool
    {
        return $user->hasPermission('image.see') || $user->hasPermission('image.upload');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('image.upload');
    }

    public function update(User $user, Image $image): bool
    {
        return $user->hasPermission('image.update');
    }

    public function edit(User $user, Image $image): bool
    {
        return $user->hasPermission('image.edit');
    }

    public function delete(User $user, Image $image): bool
    {
        return $user->hasPermission('image.delete');
    }
}
