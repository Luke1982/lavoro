<?php

namespace App\Domain\Tray\Kinds;

use App\Domain\Tray\Paste\PasteHandler;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One kind of thing that can lie on the bakje, and everything the bakje needs to
 * know about it: what it is called, which of them a person may see, how it looks
 * on the bakje, whose it is, and where it can be dropped.
 *
 * Adding a kind is one subclass and a line in TrayKinds. What every kind shares,
 * such as naming a stack of them, is written here once in terms of the few
 * questions each kind answers for itself. A kind a werkbon can be made for is a
 * WorkKind.
 */
abstract class TrayKind
{
    /** The short name the screens use for it, such as "ticket". */
    abstract public static function name(): string;

    /** @return class-string<Model> */
    abstract public static function model(): string;

    abstract public static function one(): string;

    abstract public static function many(): string;

    /**
     * Where it can be dropped, by the target type the screens send.
     *
     * @return array<string, class-string<PasteHandler>>
     */
    abstract public static function places(): array;

    /** The records of this kind the person may see, with what describing them needs. */
    abstract public static function visibleTo(User $user): Builder;

    abstract public static function title(Model $record): string;

    /** The line under its title when it is listed: where it is, or what it hangs off. */
    abstract public static function subtitle(Model $record): ?string;

    abstract public static function customerId(Model $record): ?int;

    /**
     * How it looks on the bakje, beyond what every kind shows.
     *
     * @return array<string, mixed>
     */
    protected static function card(Model $record): array
    {
        return [];
    }

    /** "storing" for one, "3 storingen" for more. */
    public static function counted(int $count): string
    {
        return $count === 1 ? static::one() : $count . ' ' . static::many();
    }

    /** "de storing", "beide storingen" or "alle 3 storingen": all of them, however many. */
    public static function every(int $count): string
    {
        return match ($count) {
            1 => 'de ' . static::one(),
            2 => 'beide ' . static::many(),
            default => 'alle ' . $count . ' ' . static::many(),
        };
    }

    /** "Storing" or "3 storingen", to start a sentence with. */
    public static function heading(int $count): string
    {
        return Str::ucfirst(static::counted($count));
    }

    /** How one of them is named in a sentence. */
    public static function named(Model $record): string
    {
        return static::title($record);
    }

    /**
     * Closes a message about the ones a paste left alone: ' "Ketel lekt" stond er
     * al op.' or ' 2 machines stonden er al op.' Nothing when there are none.
     *
     * @param  string  $one  What one of them did: "stond er al op".
     * @param  string  $many  The same for several: "stonden er al op".
     */
    public static function leftAlone(Collection $left, string $one, string $many): string
    {
        return match ($left->count()) {
            0 => '',
            1 => ' ' . static::named($left->first()) . ' ' . $one . '.',
            default => ' ' . static::counted($left->count()) . ' ' . $many . '.',
        };
    }

    /**
     * @param  array<int, int>  $ids
     */
    public static function visible(array $ids, User $user): EloquentCollection
    {
        return static::visibleTo($user)->whereIn((new (static::model()))->getQualifiedKeyName(), $ids)->get();
    }

    /**
     * What the bakje shows for one record. The customer's name is added by the
     * bakje, which looks every customer up once.
     *
     * @return array<string, mixed>
     */
    public static function describe(Model $record): array
    {
        return [
            'title' => static::title($record),
            'subtitle' => static::subtitle($record),
            'customer_id' => static::customerId($record),
            ...static::card($record),
        ];
    }

    /**
     * @return array<int, array{title: string, subtitle: ?string}>
     */
    public static function listed(Collection $records): array
    {
        return $records->map(fn (Model $record) => [
            'title' => static::title($record),
            'subtitle' => static::subtitle($record),
        ])->values()->all();
    }
}
