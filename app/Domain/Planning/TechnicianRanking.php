<?php

namespace App\Domain\Planning;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who to pick first when more people could do the job than the job needs.
 *
 * The right group first, always. A planner who asks for an airco installer
 * means an airco installer, and somebody outside the group is only ever a
 * fallback that has to be offered as one. Within the group, whoever has done
 * the most of this kind of work, and then by name, so the same question twice
 * gives the same answer.
 */
final class TechnicianRanking
{
    /**
     * The experience is per user id, as TechnicianExperience counts it.
     *
     * @param  array<int, array{installed: int, service: int, finished_orders: int, score: int}>  $experience
     */
    public function __construct(
        private readonly ?string $group,
        private readonly array $experience = [],
    ) {}

    public function inGroup(User $user): bool
    {
        return $this->group === null || in_array($this->group, $user->planGroups->pluck('name')->all(), true);
    }

    public function score(User $user): int
    {
        return $this->experience[$user->id]['score'] ?? 0;
    }

    /**
     * What somebody has done with this kind of machine, in the words an answer
     * needs. The score stays behind: it is a ranking device, not a fact.
     *
     * @return array{installed: int, service: int, finished_orders: int}
     */
    public function experienceOf(int $user_id): array
    {
        $known = $this->experience[$user_id] ?? ['installed' => 0, 'service' => 0, 'finished_orders' => 0];

        return [
            'installed' => $known['installed'],
            'service' => $known['service'],
            'finished_orders' => $known['finished_orders'],
        ];
    }

    /**
     * Position per user id, lowest first.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, int>
     */
    public function positions(Collection $users): array
    {
        return $users
            ->sort(fn (User $a, User $b) => $this->compare($a, $b))
            ->values()
            ->mapWithKeys(fn (User $user, int $position) => [$user->id => $position])
            ->all();
    }

    private function compare(User $a, User $b): int
    {
        return [!$this->inGroup($a), -$this->score($a), $a->name]
            <=> [!$this->inGroup($b), -$this->score($b), $b->name];
    }
}
