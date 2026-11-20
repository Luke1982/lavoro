<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Connectors\Afas\AfasConnector;
use App\Domain\Integrations\Connectors\BusinessCentral\BusinessCentralConnector;
use App\Domain\Integrations\Connectors\EBoekhouden\EBoekhoudenConnector;
use App\Domain\Integrations\Connectors\Exact\ExactConnector;
use App\Domain\Integrations\Connectors\Moneybird\MoneybirdConnector;
use App\Domain\Integrations\Connectors\SnelStart\SnelStartConnector;
use App\Models\IntegrationConnection;
use InvalidArgumentException;

/**
 * Every connector there is, by key. Every customer can use every one of them:
 * a connector is not a module.
 */
class Connectors
{
    /** @var array<int, class-string<Connector>> */
    private const BUILT_IN = [
        SnelStartConnector::class,
        EBoekhoudenConnector::class,
        BusinessCentralConnector::class,
        ExactConnector::class,
        AfasConnector::class,
        MoneybirdConnector::class,
    ];

    /** @var array<string, class-string<Connector>> Added by a test, never in production code. */
    private static array $extra = [];

    /** @return array<string, class-string<Connector>> */
    public static function all(): array
    {
        $all = [];

        foreach ([...self::BUILT_IN, ...array_values(self::$extra)] as $class) {
            $all[$class::key()] = $class;
        }

        return $all;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return class-string<Connector> */
    public static function classFor(string $key): string
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("No connector is called '{$key}'.");
    }

    public static function make(IntegrationConnection $connection): Connector
    {
        $class = self::classFor($connection->connector);

        return new $class($connection);
    }

    /** @param  class-string<Connector>  $class */
    public static function extend(string $class): void
    {
        self::$extra[$class::key()] = $class;
    }

    public static function forgetExtensions(): void
    {
        self::$extra = [];
    }
}
