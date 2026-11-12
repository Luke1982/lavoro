<?php

namespace App\Services;

use App\Domain\Planning\TechnicianAvailability;
use App\Exceptions\Refusal;
use App\Models\Company;
use App\Models\DayRoute;
use App\Models\User;
use App\Support\AddressFormatter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A working day along a set of addresses, there and back from the company,
 * someone's home or any other point, via a VROOM server: HeiGIT's
 * (OpenRouteService) or one of our own, see services.routeplanner.
 *
 * Each address carries the time spent there, whether it is urgent and, when it
 * has to be early, the time by which the work there must begin; the day has a
 * start and an end. VROOM ranks a solution by the sum of the priorities it
 * serves, then by the number of addresses, and only then by driving time. So
 * it puts in every urgent address it can fit, fills the day with as many
 * others as possible, and drives them in the fastest order. VROOM searches
 * rather than proves, so "practically always" is the honest promise. What does
 * not fit comes back as such, instead of a route that runs past the evening.
 *
 * VROOM does not say why an address was left out. For an ordinary one the
 * answer is plain -- the day is full -- but an urgent address, or one with a
 * vóór-time, gets its real reason: see explain().
 *
 * At HeiGIT one key serves every customer, so they all share its daily quota:
 * nothing here calculates a route on its own, only a person pressing the
 * button. Explaining costs one more calculation per three addresses that need
 * it.
 */
class RoutePlanner
{
    /** Stops in one route: with the start and the end that makes fifty points. */
    public const MAX_STOPS = 48;

    private const SERVICE_DOWN = 'De routeplanner is nu niet bereikbaar. Probeer het straks opnieuw.';

    private const OFF_ROAD = 'Een van de adressen ligt te ver van een weg; haal het uit de route.';

    /** VROOM's own code for "no route between these points". */
    private const VROOM_ROUTING_ERROR = 3;

    /** Normal addresses have priority 0, so no number of them outweighs a single urgent one. */
    private const URGENT_PRIORITY = 100;

    /** The hosted service takes no more cars than this in one request. */
    private const MAX_CARS_PER_REQUEST = 3;

    public function __construct(
        private Geocoder $geocoder,
        private TechnicianAvailability $availability,
    ) {}

    public function isAvailable(): bool
    {
        return filled(config('services.routeplanner.url'));
    }

    /**
     * What a map needs before anything is calculated: null without a route
     * server, otherwise where a route starts as far as that is known, the
     * plannable people -- whose home a route can start at, and in whose day
     * `$viewer` may put the route -- and the planner's working day and standard
     * duration to begin from. Of those people only the name goes along, never
     * where they live. Whether `$viewer` may plan at all is the page's question
     * (Gate routeplanner.use).
     * Runs while a page is drawn, so it reads the geocoder's cache and never asks.
     *
     * @return array{
     *     start: array{address: string, lat: float|null, lon: float|null}|null,
     *     people: list<array{id: int, name: string, has_address: bool, may_assign: bool}>,
     *     workday: array{start: string, end: string},
     *     visit_minutes: int
     * }|null
     */
    public function forMap(User $viewer): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $address = $this->companyAddress();
        $coordinates = $address ? $this->geocoder->cached($address) : null;

        return [
            'start' => $address ? [
                'address' => $address,
                'lat' => $coordinates['lat'] ?? null,
                'lon' => $coordinates['lon'] ?? null,
            ] : null,
            'people' => User::query()
                ->where('plannable', true)
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'has_address' => $user->homeAddress() !== null,
                    'may_assign' => $viewer->can('assign', [DayRoute::class, $user]),
                ])
                ->all(),
            'workday' => [
                'start' => sprintf('%02d:00', $this->availability->workStartHour()),
                'end' => sprintf('%02d:00', $this->availability->workEndHour()),
            ],
            'visit_minutes' => $this->availability->defaultDurationMinutes(),
        ];
    }

    /**
     * Only coordinates go to the route server; the names of the stops stay
     * here, to say which one cannot be reached. The route starts at a point,
     * at someone's home, or without either at the company.
     *
     * Times in the answer are seconds after the start of the working day.
     *
     * With `$order` nothing is chosen: the route visits exactly those stops in
     * that order, as when a calculated route is driven the other way round,
     * and only the times are worked out. Nothing is dropped then: a stop
     * reached after its vóór-time, or a day that ends late, shows in the times.
     *
     * @param  list<array{
     *     lat: float|string,
     *     lon: float|string,
     *     name?: string|null,
     *     minutes: int,
     *     urgent: bool,
     *     before?: string|null
     * }>  $stops
     * @param  array{lat: float|string, lon: float|string}|User|null  $start
     * @param  array{start: string, end: string}  $workday  as HH:MM
     * @param  list<int|string>|null  $order  positions in $stops
     * @return array{
     *     start: array{lat: float, lon: float},
     *     order: list<int>,
     *     arrivals: list<int>,
     *     unassigned: list<array{stop: int, reason: string}>,
     *     finish: int,
     *     distance: int,
     *     duration: int,
     *     path: list<array{0: float, 1: float}>
     * }
     */
    public function plan(array $stops, array|User|null $start, array $workday, ?array $order = null): array
    {
        $stops = array_values($stops);
        $start = match (true) {
            $start instanceof User => $this->homeStart($start),
            $start !== null => ['lat' => (float) $start['lat'], 'lon' => (float) $start['lon']],
            default => $this->companyStart(),
        };
        $car = self::car(1, $start, $workday);

        if ($order !== null) {
            $order = array_map('intval', $order);
            $car['steps'] = [
                ['type' => 'start'],
                ...array_map(fn ($index) => ['type' => 'job', 'id' => self::jobId($index)], $order),
                ['type' => 'end'],
            ];
        }

        $answer = $this->ask([
            'jobs' => array_map(
                fn ($index) => self::job($stops[$index], $index, $workday['start']),
                $order ?? array_keys($stops)
            ),
            'vehicles' => [$car],
            'options' => array_filter(['g' => true, 'c' => $order !== null]),
        ], $stops, $start);

        $route = self::readRoute($answer);

        if ($order !== null && $route['order'] !== $order) {
            throw new RuntimeException('The route server changed a fixed order: it does not run plan mode (-c)');
        }

        $left_over = array_map(fn ($job) => self::stopIndex($job['id']), $answer['unassigned'] ?? []);

        return ['start' => $start]
            + $route
            + ['unassigned' => $this->explain($left_over, $stops, $start, $workday)];
    }

    /**
     * Why each address that did not fit was left out. The urgent ones and those
     * with a vóór-time are tried once more, each alone in an empty day and
     * without its vóór-time: that tells when it can be reached at the earliest,
     * or that it fits in no day at all. When that try gets no answer, they get
     * the plain reason like the rest.
     *
     * @param  list<int>  $left_over  positions in $stops
     * @return list<array{stop: int, reason: string}>
     */
    private function explain(array $left_over, array $stops, array $start, array $workday): array
    {
        $puzzling = array_filter(
            $left_over,
            fn ($index) => $stops[$index]['urgent'] || filled($stops[$index]['before'] ?? null)
        );
        $earliest = $this->earliestArrivals(array_values($puzzling), $stops, $start, $workday);

        return array_map(fn ($index) => [
            'stop' => $index,
            'reason' => array_key_exists($index, $earliest)
                ? self::reason($stops[$index], $earliest[$index], $workday['start'])
                : 'Past niet meer in deze werkdag.',
        ], $left_over);
    }

    /**
     * When each of these stops is reached at the earliest as the only one of
     * the day: seconds into the day, or null when even alone it does not fit.
     * Every stop gets a car of its own, bound to it by a skill, so one request
     * answers for several. A stop whose request failed is missing from the
     * result.
     *
     * @param  list<int>  $indexes  positions in $stops
     * @return array<int, int|null>
     */
    private function earliestArrivals(array $indexes, array $stops, array $start, array $workday): array
    {
        $earliest = [];
        $bound = fn (int $index) => ['skills' => [self::jobId($index)]];
        $alone = fn (int $index) => $bound($index)
            + self::job(array_merge($stops[$index], ['before' => null]), $index, $workday['start']);
        $own_car = fn (int $index) => $bound($index) + self::car(self::jobId($index), $start, $workday);

        foreach (array_chunk($indexes, self::MAX_CARS_PER_REQUEST) as $chunk) {
            try {
                $answer = $this->ask([
                    'jobs' => array_map($alone, $chunk),
                    'vehicles' => array_map($own_car, $chunk),
                ], $stops, $start);
            } catch (Refusal) {
                continue;
            }

            $earliest += array_fill_keys($chunk, null);
            foreach ($answer['routes'] ?? [] as $route) {
                $visit = collect($route['steps'])->firstWhere('type', 'job');
                $earliest[self::stopIndex($visit['id'] ?? $visit['job'])] = (int) round($visit['arrival']);
            }
        }

        return $earliest;
    }

    /** Why a stop that was tried on its own did not make it into the route. */
    private static function reason(array $stop, ?int $earliest, string $day_start): string
    {
        $before = $stop['before'] ?? null;

        return match (true) {
            $earliest === null => 'Past niet in de werkdag, ook niet als enige adres: heen, het werk en terug'
                . ' duren langer dan de dag.',
            filled($before) && $earliest > self::secondsInto($day_start, $before) => 'Niet vóór ' . $before
                . ' te halen: op zijn vroegst om ' . self::clockAt($day_start, $earliest) . ' daar.',
            $stop['urgent'] && filled($before) => 'Vóór ' . $before
                . ' lukt niet samen met de andere spoedadressen.',
            $stop['urgent'] => 'Past niet samen met de andere spoedadressen in deze werkdag.',
            default => 'Vóór ' . $before . ' lukt niet samen met de rest van de route.',
        };
    }

    /** The car of the day: it leaves the start when the day begins and is back by its end. */
    private static function car(int $id, array $start, array $workday): array
    {
        return [
            'id' => $id,
            'profile' => 'driving-car',
            'start' => self::lonLat($start),
            'end' => self::lonLat($start),
            'time_window' => [0, self::secondsInto($workday['start'], $workday['end'])],
        ];
    }

    /**
     * One address as VROOM reads it. A vóór-time is the window in which the
     * work there has to begin, counted like every time here from the start of
     * the working day.
     */
    private static function job(array $stop, int $index, string $day_start): array
    {
        $job = [
            'id' => self::jobId($index),
            'location' => self::lonLat($stop),
            'service' => (int) $stop['minutes'] * 60,
            'priority' => $stop['urgent'] ? self::URGENT_PRIORITY : 0,
        ];

        if (filled($stop['before'] ?? null)) {
            $job['time_windows'] = [[0, self::secondsInto($day_start, $stop['before'])]];
        }

        return $job;
    }

    /** @return array{lat: float, lon: float} */
    private function companyStart(): array
    {
        $address = $this->companyAddress();

        return $this->locate(
            $address,
            'Er is geen bedrijfsadres ingevuld onder Bedrijf. Vul het in, of kies een ander startpunt.',
            'Het bedrijfsadres "' . $address . '"',
        );
    }

    /**
     * Someone's home. Whoever plans may not see the address, so no message
     * quotes it; the house on the map is all that shows.
     *
     * @return array{lat: float, lon: float}
     */
    private function homeStart(User $user): array
    {
        return $this->locate(
            $user->homeAddress(),
            $user->name . ' heeft geen thuisadres ingevuld. Kies een ander startpunt.',
            'Het thuisadres van ' . $user->name,
        );
    }

    /**
     * @param  string  $missing  what to say when there is no address
     * @param  string  $named  how the address is called in the other messages
     * @return array{lat: float, lon: float}
     */
    private function locate(?string $address, string $missing, string $named): array
    {
        if ($address === null) {
            throw new Refusal($missing);
        }

        $coordinates = $this->geocoder->lookup($address);

        if ($coordinates !== null) {
            return $coordinates;
        }

        if ($this->geocoder->recentlyMissed($address)) {
            throw new Refusal($named . ' is niet op de kaart te vinden. Kies een ander startpunt.');
        }

        throw new Refusal(
            $named . ' kon nu niet worden opgezocht. Probeer het straks opnieuw, of kies een ander startpunt.'
        );
    }

    private function companyAddress(): ?string
    {
        $company = Company::main();

        if (!$company || blank($company->address_line1) || (blank($company->postal_code) && blank($company->city))) {
            return null;
        }

        return AddressFormatter::format($company->address_line1, $company->postal_code, $company->city);
    }

    private function ask(array $body, array $stops, array $start): array
    {
        try {
            $response = Http::withHeaders(array_filter(['Authorization' => config('services.routeplanner.key')]))
                ->acceptJson()
                ->timeout(30)
                ->post(config('services.routeplanner.url'), $body);
        } catch (ConnectionException) {
            throw new Refusal(self::SERVICE_DOWN);
        }

        $answer = $response->json();

        if ($response->successful() && is_array($answer) && ($answer['code'] ?? 0) === 0) {
            return $answer;
        }

        $this->refuse($response, $stops, $start);
    }

    /**
     * Turns what the route server says into something the person at the map
     * can act on. An address off the road is theirs to solve and no fault of
     * the server, so it stays out of the log. A key that is wrong or missing is
     * not theirs to solve: that stays a real error, with the answer in the log.
     */
    private function refuse(Response $response, array $stops, array $start): never
    {
        if ($response->json('code') === self::VROOM_ROUTING_ERROR) {
            throw new Refusal(self::offRoad($response->json('error'), $stops, $start));
        }

        Log::warning('The route server refused a route', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 1000),
        ]);

        $quota_spent = $response->status() === 403 && (
            $response->header('x-ratelimit-remaining') === '0'
            || str_contains(strtolower($response->body()), 'quota')
        );

        throw match (true) {
            $response->status() === 429 => new Refusal(
                'Er worden net te veel routes tegelijk berekend. Probeer het over een minuut opnieuw.'
            ),
            $quota_spent => new Refusal(
                'Er zijn vandaag al zoveel routes berekend als het abonnement toestaat. Morgen kan het weer.'
            ),
            in_array($response->status(), [401, 403], true) => new RuntimeException(
                'The route server does not accept ROUTEPLANNER_KEY (HTTP ' . $response->status() . ')'
            ),
            $response->serverError(), !is_array($response->json()) => new Refusal(self::SERVICE_DOWN),
            default => new Refusal('De route kon niet worden berekend.'),
        };
    }

    /**
     * VROOM names the point it cannot reach by its coordinates, as "[lon,lat]"
     * or "lon lat". Matched back to what was sent, the person learns which
     * address to take out instead of guessing among twenty.
     */
    private static function offRoad(?string $error, array $stops, array $start): string
    {
        if (!preg_match('/(-?\d+\.\d+)[ ,]+(-?\d+\.\d+)/', (string) $error, $match)) {
            return self::OFF_ROAD;
        }

        $is_named = fn (array $point) => abs((float) $point['lon'] - (float) $match[1]) < 1e-5
            && abs((float) $point['lat'] - (float) $match[2]) < 1e-5;

        if ($is_named($start)) {
            return 'Het startpunt ligt te ver van een weg; kies een ander.';
        }

        $name = collect($stops)->first($is_named)['name'] ?? null;

        return filled($name) ? $name . ' ligt te ver van een weg; haal het uit de route.' : self::OFF_ROAD;
    }

    /**
     * The visiting order as positions in the list of stops that was sent, and
     * the arrival at each. Arrivals and the finish back at the start count from
     * the start of the working day. When nothing fits, the route is empty.
     */
    private static function readRoute(array $answer): array
    {
        $route = $answer['routes'][0] ?? [];
        $steps = collect($route['steps'] ?? []);
        $visits = $steps->where('type', 'job');

        return [
            'order' => $visits->map(fn ($step) => self::stopIndex($step['id'] ?? $step['job']))->values()->all(),
            'arrivals' => $visits->map(fn ($step) => (int) round($step['arrival']))->values()->all(),
            'finish' => (int) round($steps->firstWhere('type', 'end')['arrival'] ?? 0),
            'distance' => (int) round($route['distance'] ?? 0),
            'duration' => (int) round($route['duration'] ?? 0),
            'path' => isset($route['geometry']) ? self::decodePolyline($route['geometry']) : [],
        ];
    }

    /** Job ids start at 1 rather than 0, to stay clear of anything that reads 0 as "no id". */
    private static function jobId(int $stop_index): int
    {
        return $stop_index + 1;
    }

    private static function stopIndex(int|string $job_id): int
    {
        return (int) $job_id - 1;
    }

    /** The time of day `$seconds` into a day that starts at `$day_start`, rounded up to the minute. */
    private static function clockAt(string $day_start, int $seconds): string
    {
        return gmdate('H:i', self::secondsOf($day_start) + (int) ceil($seconds / 60) * 60);
    }

    /** How far "10:00" lies into a day that starts at "07:30": 9000 seconds. */
    private static function secondsInto(string $day_start, string $time): int
    {
        return self::secondsOf($time) - self::secondsOf($day_start);
    }

    /** "07:30" as seconds after midnight. */
    private static function secondsOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 3600 + $minutes * 60;
    }

    /**
     * @param  array{lat: float|string, lon: float|string}  $point
     * @return array{0: float, 1: float}
     */
    private static function lonLat(array $point): array
    {
        return [(float) $point['lon'], (float) $point['lat']];
    }

    /**
     * An encoded polyline, precision 5, as OpenRouteService returns the
     * geometry of a route.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function decodePolyline(string $encoded): array
    {
        $points = [];
        $values = [0, 0];
        $index = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            foreach ([0, 1] as $axis) {
                $result = 0;
                $shift = 0;
                do {
                    if ($index >= $length) {
                        return $points;
                    }
                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);

                $values[$axis] += ($result & 1) ? ~($result >> 1) : ($result >> 1);
            }

            $points[] = [$values[0] / 1e5, $values[1] / 1e5];
        }

        return $points;
    }
}
