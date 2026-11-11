<?php

namespace App\Services\Google;

use App\Models\DayRoute;
use Google\Service\Calendar\Event as GoogleEvent;

/**
 * A route as an all-day item in Google Calendar: the stops with their arrival
 * times and the links that drive them. It blocks no time, and it carries a mark
 * so that reading the calendar back leaves it alone: to that sync, anything it
 * does not know is a new appointment.
 */
class DayRoutePayloadBuilder
{
    private const MARK = 'lavoro_day_route';

    public function build(DayRoute $route): array
    {
        return [
            'summary' => 'Route: ' . $route->summary(),
            'description' => $this->description($route),
            'start' => ['date' => $route->date->toDateString()],
            'end' => ['date' => $route->date->addDay()->toDateString()],
            'transparency' => 'transparent',
            'status' => 'confirmed',
            'extendedProperties' => ['private' => [self::MARK => (string) $route->id]],
        ];
    }

    public static function isRoute(GoogleEvent $g_event): bool
    {
        return isset($g_event->getExtendedProperties()?->getPrivate()[self::MARK]);
    }

    private function description(DayRoute $route): string
    {
        $stops = array_map(fn (array $stop) => $stop['arrival'] . '  ' . $stop['name'], $route->stops);
        $links = count($route->links) === 1
            ? ['Openen in Google Maps: ' . $route->links[0]]
            : array_map(
                fn (string $link, int $index) => 'Deel ' . ($index + 1) . ' in Google Maps: ' . $link,
                $route->links,
                array_keys($route->links),
            );

        return implode("\n", [...$stops, 'Terug om ' . $route->finish, '', ...$links]);
    }
}
