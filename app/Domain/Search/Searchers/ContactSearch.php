<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Contact;
use App\Models\User;

class ContactSearch implements Searchable
{
    public function group(): string
    {
        return 'Contacten';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (!$user->isAdmin() && !$user->hasPermission('contact.read')) {
            return [];
        }

        $like = SearchTerm::like($term);

        return Contact::query()
            ->where(fn ($q) => $q
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('mobile', 'like', $like))
            ->orderBy('last_name')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (Contact $contact) => new SearchHit(
                $this->group(),
                $contact->full_name,
                $contact->email,
                '/contacts/' . $contact->id,
            ))
            ->all();
    }
}
