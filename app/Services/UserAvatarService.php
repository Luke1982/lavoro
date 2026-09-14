<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UserAvatarService
{
    public function save(User $user, ?UploadedFile $avatar): void
    {
        if (!($avatar instanceof UploadedFile)) {
            return;
        }

        $dir_name = 'users/' . $user->id . '/avatar';
        Storage::disk('public')->deleteDirectory($dir_name);
        $avatar->store($dir_name, 'public');
    }
}
