<?php

namespace Database\Seeders\Demo;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * What the demo seeders hand to each other: the data files, the clock, one
 * source of chance, and the records the earlier seeders made.
 *
 * One seeded randomizer and not rand(): the demo tells the same story every
 * night, only shifted to the current week. Someone who gives the demo learns
 * where the interesting cases are, and they are still there tomorrow.
 */
final class DemoContext
{
    public readonly Randomizer $random;

    /** Monday of the current week, midnight, in the company's own time zone. */
    public readonly CarbonImmutable $monday;

    public readonly CarbonImmutable $now;

    /** @var array<string, array<mixed>> */
    private array $data = [];

    /** @var array<string, User> */
    public array $users = [];

    /** @var array<int, User> */
    public array $mechanics = [];

    /** @var array<string, ProductType> leaf type name => type */
    public array $types = [];

    /** @var array<string, string> leaf type name => name of the top of its tree */
    public array $families = [];

    /** @var array<string, array<int, Product>> leaf type name => products */
    public array $products = [];

    /**
     * @var array<int, array{customer: Customer, data: array<string, mixed>, sites: array<int, array{location: ?Location, assets: array<int, Asset>}>}>
     */
    public array $customers = [];

    public function __construct(?CarbonImmutable $now = null, int $seed = 2026)
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->now = ($now ?? CarbonImmutable::now())->setTimezone(self::zone());
        $this->monday = $this->now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'Europe/Amsterdam');
    }

    /** @return array<mixed> */
    public function data(string $file): array
    {
        return $this->data[$file] ??= require base_path("database/seeders/data/demo/{$file}.php");
    }

    /**
     * @template T
     *
     * @param  array<int|string, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->random->pickArrayKeys($items, 1)[0]];
    }

    public function chance(float $probability): bool
    {
        return $this->random->getFloat(0, 1) < $probability;
    }

    public function between(int $low, int $high): int
    {
        return $this->random->getInt($low, $high);
    }

    public function decimal(float $low, float $high, int $decimals = 1): float
    {
        return round($this->random->getFloat($low, $high), $decimals);
    }

    /**
     * A wall-clock moment on a day relative to this week's Monday, in the
     * timezone the database stores (UTC). Eloquent writes a Carbon as its own wall-clock
     * text without converting, so a local time handed over as-is would land an
     * hour or two off in the planner.
     */
    public function at(int $day, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($this->monday->addDays($day)->toDateString() . ' ' . $time, self::zone())
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public function day(int $day): CarbonImmutable
    {
        return $this->monday->addDays($day);
    }
}
