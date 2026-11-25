<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Tools\ToolProfile;
use App\Models\Event;
use App\Models\User;
use InvalidArgumentException;

/**
 * Who asks, by role. What the assistant is offered and may reach differs per
 * role, so a question a monteur asks has to be asked as somebody who really is
 * one: a technician by permissions who never goes out — an office worker in the
 * demo — reads a different agenda and gets a different answer.
 *
 * MajorLabel's own super admin is never picked. It counts as an administrator,
 * but it is not one of the customer's people, and a scenario asked as that
 * account tests a role nobody at the customer has.
 */
final class EvalUsers
{
    public const ROLES = ['planner', 'monteur', 'admin'];

    /**
     * Per customer, the id of who asks in each role, or null when nobody can.
     * Worked out once per run: a scenario asks for its user several times, and
     * ids are only unique within one customer's database.
     *
     * @var array<string, array<string, ?int>>
     */
    private static array $picked = [];

    public static function forRole(string $role): ?User
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Onbekende rol "' . $role . '"; kies uit ' . implode(', ', self::ROLES) . '.');
        }

        $tenant = (string) (tenancy()->tenant?->getTenantKey() ?? '');
        $id = (self::$picked[$tenant] ??= self::pick())[$role];

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * The first user of each role, by id, going through the users only until
     * every role has somebody.
     *
     * @return array<string, ?int>
     */
    private static function pick(): array
    {
        $picked = array_fill_keys(self::ROLES, null);

        foreach (User::query()->withoutSuperAdmins()->orderBy('id')->lazy() as $user) {
            $role = match (ToolProfile::forUser($user)) {
                ToolProfile::administrator => 'admin',
                ToolProfile::planner => 'planner',
                ToolProfile::technician => 'monteur',
            };

            if ($picked[$role] === null && ($role !== 'monteur' || self::goesOut($user))) {
                $picked[$role] = $user->id;
            }

            if (!in_array(null, $picked, true)) {
                break;
            }
        }

        return $picked;
    }

    private static function goesOut(User $user): bool
    {
        return Event::query()->whereHas('executingUsers', fn ($query) => $query->where('users.id', $user->id))->exists();
    }
}
