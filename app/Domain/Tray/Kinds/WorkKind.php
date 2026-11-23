<?php

namespace App\Domain\Tray\Kinds;

use App\Actions\ServiceOrders\NewServiceOrder;
use App\Exceptions\Refusal;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A kind of thing a werkbon can be made for, when it is dropped on the planner or
 * on a project: a storing to fix, a machine to inspect. It belongs to one
 * customer and stands somewhere, and knows what a werkbon for it holds.
 */
abstract class WorkKind extends TrayKind
{
    /** Where it stands, for the werkbon made for it. */
    abstract public static function locationId(Model $record): ?int;

    /** Why there is no customer to make a werkbon for. */
    abstract protected static function withoutCustomer(Model $record): string;

    /**
     * What a new werkbon holds, as arguments to NewServiceOrder.
     *
     * @return array<string, mixed>
     */
    abstract protected static function serviceOrderContents(Collection $records): array;

    /** What a new werkbon for these says it is for. */
    abstract protected static function serviceOrderDescription(Collection $records): string;

    /**
     * The one customer all of these belong to, or a refusal that says why there
     * is none.
     */
    public static function sharedCustomer(Collection $records): Customer
    {
        $customer_ids = $records->map(
            fn (Model $record) => static::customerId($record) ?? throw new Refusal(static::withoutCustomer($record)),
        )->unique();

        if ($customer_ids->count() > 1) {
            throw new Refusal('Deze ' . static::many() . ' horen bij verschillende klanten. '
                . 'Pak ze los uit de stapel en plak ze per klant.');
        }

        return Customer::findOrFail($customer_ids->first());
    }

    /**
     * Where a werkbon for these is, when they are all in the same place. Spread
     * over several, a guess would be worse than leaving it empty.
     */
    public static function sharedLocationId(Collection $records): ?int
    {
        $location_ids = $records->map(fn (Model $record) => static::locationId($record))->unique();

        return $location_ids->count() === 1 ? $location_ids->first() : null;
    }

    /**
     * A new werkbon for these, for their customer and where they are, through
     * the same action the werkbon screen uses.
     */
    public static function newServiceOrder(Collection $records, ?int $project_id = null): NewServiceOrder
    {
        return new NewServiceOrder(...[
            'customer_id' => static::sharedCustomer($records)->id,
            'project_id' => $project_id,
            'location_id' => static::sharedLocationId($records),
            'description' => mb_strimwidth(static::serviceOrderDescription($records), 0, 255, '…'),
            ...static::serviceOrderContents($records),
        ]);
    }
}
