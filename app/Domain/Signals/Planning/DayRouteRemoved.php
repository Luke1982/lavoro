<?php

namespace App\Domain\Signals\Planning;

use App\Models\DayRoute;
use App\Models\User;

/** A route was taken out of someone's day. It carries where it stood in Google, as the route itself is gone. */
class DayRouteRemoved extends DayRouteSignal
{
    /** @var array<int|string, string> Google's event id per synced calendar id */
    public array $google_items;

    public function __construct(DayRoute $route, User $mechanic)
    {
        parent::__construct($route, $mechanic);

        $this->google_items = $route->google_items ?? [];
    }

    public static function key(): string
    {
        return 'day_route.removed';
    }

    public static function label(): string
    {
        return 'Route uit de planning gehaald';
    }

    public function activityDescription(): ?string
    {
        return 'Route uit de planning gehaald voor ' . $this->day_label . ' (' . $this->summary . ').';
    }

    public function changes(): array
    {
        return $this->routeChange($this->summary, null);
    }
}
