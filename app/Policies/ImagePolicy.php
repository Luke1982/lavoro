<?php

namespace App\Policies;

use App\Models\Image;
use App\Models\User;

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

    /** Also asked of the class: choosing a main photo is the same right for every photo. */
    public function update(User $user, ?Image $image = null): bool
    {
        return $user->hasPermission('image.update');
    }

    /**
     * Sharing changes where a photo shows, like choosing the main photo does.
     */
    public function share(User $user, Image $image): bool
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
