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
        if (!$user->isAdmin() && !$user->hasPermission('project.read')) {
            return [];
        }

        $like = SearchTerm::like($term);

        return Project::query()
            ->where(fn ($q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('location', 'like', $like)
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', $like)))
            ->with('customer:id,name')
            ->orderBy('title')
            ->limit($limit)
            ->get(['id', 'title', 'status', 'customer_id'])
            ->map(fn (Project $project) => new SearchHit(
                $this->group(),
                $project->title,
                collect([$project->customer?->name, $project->status])->filter()->implode(' · '),
                '/projects/' . $project->id,
            ))
            ->all();
    }
}
