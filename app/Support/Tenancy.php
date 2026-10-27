<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Running something inside one customer's database.
 *
 * Exists because pairing initialize() and end() by hand goes wrong as soon as
 * something in between throws: the tenant stays open and the next round -- or
 * the rest of the request -- runs in the previous customer's database. That
 * produces no error at all, only the wrong data.
 *
 * And not tenancy()->runForMultiple(): that only puts the previous tenant back
 * when nothing goes wrong -- the restore sits after the loop and not in a
 * finally -- and it returns nothing. Precisely the two things this is for.
 */
final class Tenancy
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function within(Tenant $tenant, callable $work): mixed
    {
        $previous = tenancy()->initialized ? tenancy()->tenant : null;

        tenancy()->initialize($tenant);

        try {
            $result = $work();

            /**
             * Job::dispatch() queues nothing: it returns a PendingDispatch that
             * only does that in its destructor. An arrow function hands that
             * value to this function, and then the object falls apart out here
             * -- after tenancy has been ended below. The job went into the
             * queue without a customer and ran against the central database at
             * the worker.
             *
             * That raised no error while scheduling, only later: 'Base table
             * lavoro_landlord.google_synced_calendars doesn't exist', every
             * five minutes again. Setting it to null lets php clean the object
             * up here, with the customer still open.
             */
            if ($result instanceof PendingDispatch) {
                $result = null;
            }

            return $result;
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
        }
    }

    /** Can this customer's database be opened? */
    public static function reachable(Tenant $tenant): bool
    {
        try {
            return (bool) self::within($tenant, fn () => DB::connection('tenant')->getPdo());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Doing the same for every customer that can be reached.
     *
     * A customer whose database is gone makes every task about them fall over.
     * For work that runs every five minutes that is hundreds of failed jobs a
     * day, and everything real disappears in between: production had 1313 of
     * them, all from the same broken customer.
     *
     * Skipped and not kept quiet: it goes in the log, and the doctor reports
     * such a customer separately.
     */
    public static function forEachReachable(callable $work): void
    {
        Tenant::on('central')->cursor()->each(function (Tenant $tenant) use ($work) {
            if (!static::reachable($tenant)) {
                Log::warning('Tenant skipped: its database will not open.', [
                    'tenant' => $tenant->id,
                    'name' => $tenant->name,
                ]);

                return;
            }

            static::within($tenant, $work);
        });
    }
}
