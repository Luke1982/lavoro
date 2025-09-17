<?php

namespace App\Models\Traits;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasActivities
{
    public function activities(): MorphToMany
    {
        return $this->morphToMany(Activity::class, 'activityable')->withTimestamps();
    }

    public function logActivity(
        string $description,
        ?\DateTimeInterface $occurred_at = null,
        ?string $category = null
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
                str_contains($lower, 'status') => 'status',
                str_contains($lower, 'keuring toegevoegd') => 'created',
                default => 'other',
            };
        }

        $activity = Activity::create([
            'description' => $description,
            'category' => $category,
        ]);
        $this->activities()->attach($activity->id);
        return $activity;
    }
}
