<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Models\Event;
use App\Models\User;
use App\Services\EventLocationResolver;

/**
 * Afspraken have no page of their own, so a hit uses the planner's own deep link
 * (see usePlannerJump): highlightevent picks the appointment, gotodate moves the
 * view to its week, and executing_user_ids is what stops the planner reporting
 * the monteur as hidden and showing nothing.
 *
 * Matches through Event::matchesSearch, the same as the search bar in the
 * planner, so a customer, an address or a werkbon number finds the appointment
 * here too. That is also how the planner search reaches a phone, where the
 * planner has no search bar of its own.
 */
class EventSearch implements Searchable
{
    public function group(): string
    {
        return 'Afspraken';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if ($user->cannot('viewAny', Event::class)) {
            return [];
        }

        return Event::query()
            ->visibleTo($user)
            ->withinHorizonOf($user)
            ->matchesSearch($term)
            ->with([...EventLocationResolver::relations(), 'eventType:id,name', 'executingUsers:id,name'])
            /**
             * Nearest to today first. Sorting on the date itself would put an
             * appointment two years out above the one happening tomorrow, which
             * is never the one being looked for.
             */
            ->orderByRaw('ABS(DATEDIFF(start, CURDATE()))')
            ->limit($limit)
            /** location_id is selected because Event::$with resolves linkedLocation through it. */
            ->get(['id', 'name', 'start', 'location', 'location_id', 'status', 'event_type_id'])
            ->map(fn (Event $event) => new SearchHit(
                $this->group(),
                $event->name ?: 'Afspraak ' . $event->id,
                collect([
                    $event->start?->format('d-m-Y H:i'),
                    $event->customerName(),
                    $event->display_location,
                ])->filter()->implode(' · '),
                $this->plannerLink($event),
                collect([$event->eventType?->name, ...$event->executingUsers->pluck('name')])
                    ->filter()
                    ->values()
                    ->all(),
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
