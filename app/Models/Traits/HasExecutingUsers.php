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

    public function syncExecutingUsers(
        array $user_ids,
        array $breaktimes = [],
        array $user_roles = [],
        array $diverging_times = []
    ): void {
        $current_ids = $this->executingUsers()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $new_ids = array_values(array_unique(array_map('intval', $user_ids)));

        $to_remove = array_diff($current_ids, $new_ids);
        $to_add    = array_diff($new_ids, $current_ids);
        $to_keep   = array_intersect($current_ids, $new_ids);

        if ($to_remove) {
            $this->executingUsers()->detach(array_values($to_remove));
        }

        foreach ($to_keep as $uid) {
            $update = [];

            if (array_key_exists($uid, $breaktimes)) {
                $update['breaktime'] = (int) $breaktimes[$uid];
            }

            if (array_key_exists($uid, $diverging_times)) {
                $dt  = (array) $diverging_times[$uid];
                $has = (bool) ($dt['has_diverging_times'] ?? false);
                $update['has_diverging_times'] = $has;
                $update['diverging_start']     = $has ? ($dt['diverging_start'] ?? null) : null;
                $update['diverging_end']       = $has ? ($dt['diverging_end'] ?? null) : null;
            }

            if ($update) {
                $this->executingUsers()->updateExistingPivot($uid, $update);
            }
        }

        $attach = [];
        foreach ($to_add as $uid) {
            $dt  = $diverging_times[$uid] ?? [];
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

        $userable_map = DB::table('userables')
            ->where('userable_type', $this->getMorphClass())
            ->where('userable_id', $this->getKey())
            ->where('type', 'executing')
            ->pluck('id', 'user_id');

        if ($userable_map->isEmpty()) {
            return;
        }

        DB::table('user_role_userable')
            ->whereIn('userable_id', $userable_map->values()->all())
            ->delete();

        $inserts = [];
        foreach ($userable_map as $user_id => $userable_id) {
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

    public function executingUserRoleIds(int $user_id): array
    {
        $userable_id = DB::table('userables')
            ->where('userable_type', $this->getMorphClass())
            ->where('userable_id', $this->getKey())
            ->where('type', 'executing')
            ->where('user_id', $user_id)
            ->value('id');

        if (! $userable_id) {
            return [];
        }

        return DB::table('user_role_userable')
            ->where('userable_id', $userable_id)
            ->pluck('user_role_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function executingUser()
    {
        return $this->executingUsers()->first();
    }
}
