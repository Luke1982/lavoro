<?php

namespace App\Services;

use App\Enums\EventTrigger;
use App\Models\Event;
use App\Models\StandardEmailTrigger;
use Illuminate\Support\Collection;

class StandardEmailTriggerResolver
{
    /**
     * @param  string[]  $triggerTypes
     * @return Collection<int, StandardEmailTrigger>
     */
    public static function matching(Event $event, EventTrigger $trigger, array $triggerTypes): Collection
    {
        return StandardEmailTrigger::query()
            ->where('trigger', $trigger->name)
            ->whereIn('trigger_type', $triggerTypes)
            ->whereHas('standardEmail')
            ->with('standardEmail')
            ->get();
    }
}
