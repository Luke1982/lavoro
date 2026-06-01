<?php

namespace App\Models\Traits;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Auth;

trait HasActivities
{
    public function activities(): MorphToMany
    {
        return $this->morphToMany(Activity::class, 'activityable')->withTimestamps();
    }

    /**
     * Log an activity attached to this model.
     *
     * @param  array<int, Model>  $also_attach_to  Additional models that use
     *         HasActivities to attach the same activity to.
     */
    public function logActivity(
        string $description,
        ?\DateTimeInterface $occurred_at = null,
        ?string $category = null,
        ?User $user = null,
        array $also_attach_to = []
    ): Activity {
        if (!$category) {
            $lower = mb_strtolower($description);
            $category = match (true) {
                str_contains($lower, 'materiaal toegevoegd') => 'material',
                str_contains($lower, 'materiaal verwijderd') => 'material',
                str_contains($lower, 'materiaal hoeveelheid') => 'material',
                str_contains($lower, 'ticket gekoppeld') => 'ticket',
                str_contains($lower, 'ticket losgekoppeld') => 'ticket',
                str_contains($lower, 'werkbon per e-mail') => 'email',
                str_contains($lower, 'keuring per e-mail') => 'email',
                (
                    str_contains($lower, 'e-mail')
                    && (
                        str_contains($lower, 'verzonden')
                        || str_contains($lower, 'verstuurd')
                    )
                ) => 'email',
                str_contains($lower, 'fase') => 'stage',
                str_contains($lower, 'status') => 'status',
                str_contains($lower, 'keuring toegevoegd') => 'created',
                default => 'other',
            };
        }

        $resolved_user = $user ?? Auth::user();

        $activity = Activity::create([
            'description' => $description,
            'category' => $category,
            'user_id' => $resolved_user?->id,
        ]);
        $this->activities()->attach($activity->id);

        foreach ($also_attach_to as $model) {
            if (!method_exists($model, 'activities')) {
                continue;
            }
            $model->activities()->attach($activity->id);
        }

        return $activity;
    }
}
