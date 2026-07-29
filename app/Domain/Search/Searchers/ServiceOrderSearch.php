<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\ServiceOrder;
use App\Models\User;

class ServiceOrderSearch implements Searchable
{
    /** Beyond this the days stop being readable at a glance and become a count. */
    private const SHOWN_DATES = 3;

    public function group(): string
    {
        return 'Werkbonnen';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $like = SearchTerm::like($term);

        return ServiceOrder::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->where('description', 'like', $like)
                ->orWhere('external_purchaseorder_no', 'like', $like)
                ->orWhere('external_invoice_no', 'like', $like)
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', $like))
                ->when(ctype_digit($term), fn ($nq) => $nq->orWhere('id', (int) $term)))
            ->with([
                'customer:id,name',
                'serviceOrderStage:id,name',
                'events:id,start',
            ])
            /**
             * ServiceOrder::$with also pulls linkedLocation on every query. A hit
             * does not show it and its foreign key is not even selected here, so
             * it would cost a query to fetch nothing.
             */
            ->without(['linkedLocation'])
            ->orderByDesc('id')
            ->limit($limit)
            /** service_order_stage_id is selected because the stage is eager-loaded through it. */
            ->get(['id', 'description', 'customer_id', 'order_date', 'service_order_stage_id'])
            ->map(fn (ServiceOrder $service_order) => new SearchHit(
                $this->group(),
                '#' . $service_order->id . ' ' . ($service_order->customer?->name ?: ''),
                collect([
                    $service_order->order_date?->format('d-m-Y'),
                    $service_order->description,
                ])->filter()->implode(' · '),
                '/serviceorders/' . $service_order->id,
                $this->meta($service_order),
            ))
            ->all();
    }

    /**
     * Always the stage first and the planned days second, both of them always
     * present. A missing stage and an unplanned werkbon are worth saying out loud
     * rather than leaving as a gap, and holding the order fixed is what lets the
     * row style the two differently without guessing which one it is looking at.
     *
     * @return string[]
     */
    private function meta(ServiceOrder $service_order): array
    {
        return [
            $service_order->serviceOrderStage?->name ?: 'Geen fase',
            $this->plannedDays($service_order),
        ];
    }

    private function plannedDays(ServiceOrder $service_order): string
    {
        $dates = $service_order->events
            ->pluck('start')
            ->filter()
            ->sort()
            ->map(fn ($start) => $start->format('d-m-Y'))
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return 'Niet gepland';
        }

        $shown = $dates->take(self::SHOWN_DATES);
        $remaining = $dates->count() - $shown->count();

        return $shown->implode(', ') . ($remaining > 0 ? ' +' . $remaining : '');
    }
}
