<?php

namespace App\Models\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

trait HasExecutingUsers
{
    public function executingUsers(): MorphToMany
    {
        return $this
            ->morphToMany(User::class, 'userable')
            ->withPivot('id', 'type', 'breaktime', 'has_diverging_times', 'diverging_start', 'diverging_end')
            ->wherePivot('type', 'executing')
            ->withTimestamps();
    }

    public function hasExecutingUser(int $user_id): bool
    {
        return $this->executingUsers()->where('users.id', $user_id)->exists();
    }

    public function addExecutingUser(int $user_id): void
    {
        if (! $this->hasExecutingUser($user_id)) {
            $this->executingUsers()->attach($user_id, ['type' => 'executing']);
        }
    }

    public function syncSingleExecutingUser(int $user_id): void
    {
        $this->executingUsers()->detach();
        $this->executingUsers()->attach($user_id, ['type' => 'executing']);
    }

    public function syncExecutingUsers(
        array $user_ids,
        array $breaktimes = [],
        array $user_roles = [],
        array $diverging_times = []
    ): void {
        $this->executingUsers()->detach();
        $attach = [];
        foreach (array_unique($user_ids) as $uid) {
            $dt = $diverging_times[$uid] ?? $diverging_times[(string) $uid] ?? [];
            $has = (bool) ($dt['has_diverging_times'] ?? false);
            $attach[$uid] = [
                'type'                => 'executing',
                'breaktime'           => (int) ($breaktimes[$uid] ?? 0),
                'has_diverging_times' => $has,
                'diverging_start'     => $has ? ($dt['diverging_start'] ?? null) : null,
                'diverging_end'       => $has ? ($dt['diverging_end'] ?? null) : null,
            ];
        }
        if ($attach) {
            $this->executingUsers()->attach($attach);
        }
        $this->syncExecutingUserRoles($user_roles);
    }

    protected function syncExecutingUserRoles(array $user_roles): void
    {
        if (empty($user_roles)) {
            return;
        }

        $userable_ids = DB::table('userables')
            ->where('userable_type', $this->getMorphClass())
            ->where('userable_id', $this->getKey())
            ->where('type', 'executing')
            ->pluck('id', 'user_id');

        $inserts = [];
        foreach ($userable_ids as $user_id => $userable_id) {
            $role_ids = $user_roles[$user_id] ?? $user_roles[(string) $user_id] ?? [];
            foreach (array_unique(array_map('intval', (array) $role_ids)) as $role_id) {
                if ($role_id > 0) {
                    $inserts[] = [
                        'userable_id'  => $userable_id,
                        'user_role_id' => $role_id,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ];
                }
            }
        }

        if ($inserts) {
            DB::table('user_role_userable')->insert($inserts);
        }
    }

    public function executingUser()
    {
        return $this->executingUsers()->first();
    }
}
