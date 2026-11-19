<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Project;
use App\Models\User;

class ProjectSearch implements Searchable
{
    public function group(): string
    {
        return 'Projecten';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $like = SearchTerm::like($term);

        return Project::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->matchesSearch($like)
                ->orWhere('description', 'like', $like)
                ->orWhere('location', 'like', $like)
                ->orWhereHas('linkedLocation', fn ($lq) => $lq->matchesText($like)))
            ->with('customer:id,name')
            ->inNumberOrder()
            ->limit($limit)
            ->get(['id', 'number', 'title', 'status', 'customer_id'])
            ->map(fn (Project $project) => new SearchHit(
                $this->group(),
                $project->label(),
                collect([$project->customer?->name, $project->status])->filter()->implode(' · '),
                '/projects/' . $project->id,
            ))
            ->all();
    }
}
