<?php

namespace App\Models\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasExecutingUsers
{
    public function executingUsers(): MorphToMany
    {
        return $this
            ->morphToMany(User::class, 'userable')
            ->withPivot('type')
            ->wherePivot('type', 'executing')
            ->withTimestamps();
    }

    public function hasExecutingUser(int $user_id): bool
    {
        return $this->executingUsers()->where('users.id', $user_id)->exists();
    }

    public function addExecutingUser(int $user_id): void
    {
        if (!$this->hasExecutingUser($user_id)) {
            $this->executingUsers()->attach($user_id, ['type' => 'executing']);
        }
    }

    public function syncSingleExecutingUser(int $user_id): void
    {
        $this->executingUsers()->detach();
        $this->executingUsers()->attach($user_id, ['type' => 'executing']);
    }

    public function syncExecutingUsers(array $user_ids): void
    {
        $this->executingUsers()->detach();
        $attach = [];
        foreach (array_unique($user_ids) as $uid) {
            $attach[$uid] = ['type' => 'executing'];
        }
        if ($attach) {
            $this->executingUsers()->attach($attach);
        }
    }

    public function executingUser()
    {
        return $this->executingUsers()->first();
    }
}
