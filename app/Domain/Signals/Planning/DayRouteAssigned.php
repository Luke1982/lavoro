<?php

namespace App\Domain\Signals\Planning;

use App\Models\DayRoute;
use App\Models\User;

/** A route from the map was put in someone's day, possibly in place of the one that was there. */
class DayRouteAssigned extends DayRouteSignal
{
    public function __construct(public DayRoute $route, User $mechanic, public ?string $replaced_summary)
    {
        parent::__construct($route, $mechanic);
    }

    public static function key(): string
    {
        return 'day_route.assigned';
    }

    public static function label(): string
    {
        return 'Route in de planning gezet';
    }

    public function activityDescription(): ?string
    {
        return ($this->replaced_summary === null ? 'Route in de planning gezet' : 'Route in de planning vervangen')
            . ' voor ' . $this->day_label . ': ' . $this->summary . '.';
    }

    public function changes(): array
    {
        return $this->routeChange($this->replaced_summary, $this->summary);
    }
}
