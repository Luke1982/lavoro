<?php

namespace App\Domain\Tray;

use App\Models\Asset;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * What can be put on the bakje, by the short name the screens use for it.
 *
 * Every kind answers one question the bakje cannot answer for it: which of these
 * records may this person still see. Something that has been deleted, or moved
 * out of their sight, falls off their bakje the next time it is read.
 */
final class TrayKinds
{
    /** @var array<string, array{class: class-string, one: string, many: string}> */
    private const KINDS = [
        'ticket' => ['class' => Ticket::class, 'one' => 'storing', 'many' => 'storingen'],
        'asset' => ['class' => Asset::class, 'one' => 'machine', 'many' => 'machines'],
    ];

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::KINDS);
    }

    /** @return class-string */
    public static function classFor(string $kind): string
    {
        return self::KINDS[$kind]['class'];
    }

    public static function kindFor(string $class): ?string
    {
        foreach (self::KINDS as $kind => $definition) {
            if ($definition['class'] === ltrim($class, '\\')) {
                return $kind;
            }
        }

        return null;
    }

    /** "storing" for one, "3 storingen" for more. */
    public static function counted(string $kind, int $count): string
    {
        return $count === 1 ? self::KINDS[$kind]['one'] : $count . ' ' . self::KINDS[$kind]['many'];
    }

    /**
     * @param  array<int, int>  $ids
     */
    public static function visible(string $kind, array $ids, User $user): Collection
    {
        return match ($kind) {
            'ticket' => Ticket::visibleTo($user)
                ->whereIn('id', $ids)
                ->with(['asset.product.brand', 'serviceOrder'])
                ->get(),
            'asset' => Asset::visibleTo($user)
                ->whereIn('id', $ids)
                ->with(['product.brand', 'product.productType', 'linkedLocation', 'parentAsset.product.brand'])
                ->get(),
        };
    }
}
