<?php

namespace App\Domain\Signals\Planning;

use App\Domain\Signals\BaseSignal;
use App\Models\DayRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Something happened to the route in someone's day. The fact is about that
 * person's day, so it is on their record, and what the route said is kept as it
 * was at that moment: by the time anyone reads this it may be replaced or gone.
 */
abstract class DayRouteSignal extends BaseSignal
{
    public string $day_label;

    public string $summary;

    public function __construct(DayRoute $route, public User $mechanic)
    {
        parent::__construct();

        $this->day_label = $route->dayLabel();
        $this->summary = $route->summary();
    }

    public function subject(): Model
    {
        return $this->mechanic;
    }

    /** @return array<int, array<string, string|null>> */
    protected function routeChange(?string $before, ?string $after): array
    {
        return [[
            'field' => 'day_route',
            'label' => 'Route ' . $this->day_label,
            'old_value' => $before,
            'new_value' => $after,
            'old_label' => $before,
            'new_label' => $after,
        ]];
    }
}
