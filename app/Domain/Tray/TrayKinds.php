<?php

namespace App\Domain\Tray;

use App\Domain\Tray\Kinds\AssetKind;
use App\Domain\Tray\Kinds\TicketKind;
use App\Domain\Tray\Kinds\TrayKind;
use App\Domain\Tray\Paste\PasteHandler;
use App\Domain\Tray\Paste\UndoablePasteHandler;
use App\Exceptions\Refusal;

/**
 * Every kind of thing that can lie on the bakje, and what follows from all of
 * them together: which places take what, and which pastes can be taken back.
 *
 * The screens are told where each kind can go rather than knowing it themselves,
 * so a place offered on a page is always one the server will accept.
 */
final class TrayKinds
{
    /** @var array<int, class-string<TrayKind>> */
    private const KINDS = [
        TicketKind::class,
        AssetKind::class,
    ];

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_map(fn (string $kind) => $kind::name(), self::KINDS);
    }

    /** @return class-string<TrayKind> */
    public static function get(string $name): string
    {
        foreach (self::KINDS as $kind) {
            if ($kind::name() === $name) {
                return $kind;
            }
        }

        throw new Refusal('Dit kan niet op het bakje.');
    }

    /** @return class-string<TrayKind>|null */
    public static function forModel(string $class): ?string
    {
        foreach (self::KINDS as $kind) {
            if ($kind::model() === ltrim($class, '\\')) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The handler for this kind dropped on this kind of place.
     *
     * @param  class-string<TrayKind>  $kind
     */
    public static function handler(string $kind, string $target): PasteHandler
    {
        $handler = $kind::places()[$target]
            ?? throw new Refusal('Een ' . $kind::one() . ' kun je hier niet plakken.');

        return app($handler);
    }

    /**
     * Each kind as the screens need it: what to call it and where it can go.
     *
     * @return array<string, array{one: string, many: string, places: array<int, string>}>
     */
    public static function forScreens(): array
    {
        return collect(self::KINDS)
            ->mapWithKeys(fn (string $kind) => [$kind::name() => [
                'one' => $kind::one(),
                'many' => $kind::many(),
                'places' => array_keys($kind::places()),
            ]])
            ->all();
    }

    /** @return array<int, string> Every place something can be dropped. */
    public static function targets(): array
    {
        return collect(self::KINDS)
            ->flatMap(fn (string $kind) => array_keys($kind::places()))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> The pastes that can be taken back, as "kind:target". */
    public static function undoable(): array
    {
        return collect(self::KINDS)
            ->flatMap(fn (string $kind) => collect($kind::places())
                ->filter(fn (string $handler) => is_subclass_of($handler, UndoablePasteHandler::class))
                ->keys()
                ->map(fn (string $target) => $kind::name() . ':' . $target))
            ->values()
            ->all();
    }
}
