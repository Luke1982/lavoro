<?php

namespace App\Policies;

use App\Models\Image;
use App\Models\Share;
use App\Models\User;

class ImagePolicy
{
    /**
     * Photos follow the record they hang off: whoever may open the record sees
     * them, and which ones is Image::scopeVisibleTo's business.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** This one photo: on a record this person may open, or shared to one (Image::scopeVisibleTo). */
    public function view(User $user, Image $image): bool
    {
        return Image::visibleTo($user)->whereKey($image->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('image.upload');
    }

    /**
     * Also asked of the class: choosing a main photo is the same right for every photo.
     * Changing, sharing, annotating and removing one take a photo this person may
     * open (view), and leave a keurpunt of a keuring they may only read alone
     * (keurpuntenAllowChangesBy).
     */
    public function update(User $user, ?Image $image = null): bool
    {
        return $user->hasPermission('image.update')
            && ($image === null || $this->mayChange($user, $image));
    }

    /**
     * Sharing changes where a photo shows, like choosing the main photo does.
     */
    public function share(User $user, Image $image): bool
    {
        return $user->hasPermission('image.update') && $this->mayChange($user, $image)
            && Share::mayShareFrom($user, $image->attachedRecord());
    }

    public function edit(User $user, Image $image): bool
    {
        return $user->hasPermission('image.edit') && $this->mayChange($user, $image);
    }

    public function delete(User $user, Image $image): bool
    {
        return $user->hasPermission('image.delete') && $this->mayChange($user, $image);
    }

    private function mayChange(User $user, Image $image): bool
    {
        return $this->view($user, $image) && $image->keurpuntenAllowChangesBy($user);
    }
}
