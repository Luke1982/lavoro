<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Ticket;
use App\Models\User;

class TicketSearch implements Searchable
{
    public function group(): string
    {
        return 'Storingen';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $like = SearchTerm::like($term);

        return Ticket::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->where('subject', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('status_code', 'like', $like)
                ->when(ctype_digit($term), fn ($nq) => $nq->orWhere('id', (int) $term)))
            ->with(['asset:id,serial_number,customer_id', 'asset.customer:id,name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'asset_id'])
            ->map(fn (Ticket $ticket) => new SearchHit(
                $this->group(),
                '#' . $ticket->id . ' ' . $ticket->subject,
                collect([$ticket->asset?->customer?->name, $ticket->status])->filter()->implode(' · '),
                '/tickets/' . $ticket->id,
            ))
            ->all();
    }
}
