<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A plan role: what someone does on an appointment (monteur, leerling,
 * chauffeur), not what they may do in the application.
 */
class UserRole extends Model
{
    use HasActivities;
    use RecordsHistory;

    protected $fillable = [
        'name',
        'color',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'color' => 'Kleur',
    ];

    /**
     * Everyone who has it on an appointment, taken just before the database
     * takes it off all of them: the "Verwijderd" line goes on their timelines,
     * because the role's own is gone with it.
     *
     * @var Collection<int, User>|null
     */
    private ?Collection $holders_at_deletion = null;

    protected static function booted(): void
    {
        static::deleting(function (self $role) {
            $role->holders_at_deletion = User::withoutSuperAdmins()
                ->whereIn('id', DB::table('user_role_userable')
                    ->join('userables', 'userables.id', '=', 'user_role_userable.userable_id')
                    ->where('user_role_userable.user_role_id', $role->id)
                    ->select('userables.user_id'))
                ->get();
        });
    }

    /** @return array<int, User> */
    public function historyTimelines(string $action): array
    {
        return $action === 'deleted' ? ($this->holders_at_deletion ?? collect())->all() : [];
    }

    public function historyLabel(): ?string
    {
        return 'Planrol "' . $this->name . '"';
    }
}
