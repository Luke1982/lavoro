<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** A row of people in the planner, under a name and a colour of its own. */
class UserPlanGroup extends Model
{
    use HasActivities;
    use RecordsHistory;

    protected $fillable = ['name', 'color', 'sort_order'];

    protected array $activity_labels = [
        'name' => 'Naam',
        'color' => 'Kleur',
    ];

    /** Dragging the groups into another order is arranging the screen, not a change to a group. */
    protected array $activity_ignore = [
        'sort_order',
    ];

    /**
     * Who was in it, taken just before the database takes them out of it: the
     * "Verwijderd" line goes on their timelines, the one place left that says
     * they were ever in this group.
     *
     * @var Collection<int, User>|null
     */
    private ?Collection $members_at_deletion = null;

    protected static function booted(): void
    {
        static::deleting(function (self $group) {
            $group->members_at_deletion = $group->users()->withoutSuperAdmins()->get();
        });
    }

    /** @return array<int, User> */
    public function historyTimelines(string $action): array
    {
        return $action === 'deleted' ? ($this->members_at_deletion ?? collect())->all() : [];
    }

    public function historyLabel(): ?string
    {
        return 'Plangroep "' . $this->name . '"';
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'plan_group_user')->withTimestamps();
    }
}
