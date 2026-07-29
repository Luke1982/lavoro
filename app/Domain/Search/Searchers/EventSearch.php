<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Event;
use App\Models\User;
use App\Services\EventLocationResolver;

/**
 * Afspraken have no page of their own, so a hit uses the planner's own deep link
 * (see the onMounted handler in ResourcePlannerWidget): highlightevent picks the
 * appointment, gotodate moves the view to its week, and executing_user_ids is
 * what stops the planner reporting the monteur as hidden and showing nothing.
 */
class EventSearch implements Searchable
{
    public function group(): string
    {
        return 'Afspraken';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $like = SearchTerm::like($term);

        return Event::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('location', 'like', $like))
            ->with([...EventLocationResolver::relations(), 'executingUsers:id'])
            /**
             * Nearest to today first. Sorting on the date itself would put an
             * appointment two years out above the one happening tomorrow, which
             * is never the one being looked for.
             */
            ->orderByRaw('ABS(DATEDIFF(start, CURDATE()))')
            ->limit($limit)
            /** location_id is selected because Event::$with resolves linkedLocation through it. */
            ->get(['id', 'name', 'start', 'location', 'location_id', 'status'])
            ->map(fn (Event $event) => new SearchHit(
                $this->group(),
                $event->name ?: 'Afspraak ' . $event->id,
                collect([$event->start?->format('d-m-Y H:i'), $event->display_location])->filter()->implode(' · '),
                $this->plannerLink($event),
            ))
            ->all();
    }

    private function plannerLink(Event $event): string
    {
        return '/planner?' . http_build_query([
            'highlightevent' => $event->id,
            'gotodate' => $event->start?->toIso8601String(),
            'executing_user_ids' => $event->executingUsers->pluck('id')->implode(','),
        ]);
    }
}
