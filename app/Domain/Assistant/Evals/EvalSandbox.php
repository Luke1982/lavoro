<?php

namespace App\Domain\Assistant\Evals;

use App\Models\Tenant;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * What the eval and the probe put around a customer's real data before asking
 * anything: one run at a time per customer, nothing leaving the building, and
 * every question in a transaction that is rolled back.
 */
final class EvalSandbox
{
    /** The disks a carried-out action writes to: save_document files the offerte on public, the box parks uploads on local. */
    private const DISKS = ['public', 'local'];

    /** @param  resource  $lock */
    private function __construct(private $lock, private readonly ?string $disks) {}

    /**
     * Runs the work inside a sandbox and closes it afterwards, also when the
     * work threw: the lock is released and the run's files removed either way.
     *
     * One run at a time per customer. Two runs side by side both created the
     * brand Tosot inside their own open transaction, and the second one died on
     * a lock wait that no person in the box would ever meet.
     *
     * With fake_disks, files written during the run land in a folder of their
     * own that is removed afterwards, because a rollback does not take a file
     * back. The customer's existing photos and documents are then out of sight.
     *
     * @template T
     *
     * @param  Closure(): void  $waiting  Told when another run holds the lock.
     * @param  Closure(): T  $work
     * @return T
     */
    public static function around(Tenant $tenant, bool $fake_disks, Closure $waiting, Closure $work): mixed
    {
        $sandbox = self::open($tenant, $fake_disks, $waiting);

        try {
            $result = $work();
        } catch (Throwable $e) {
            $sandbox->close($e);

            throw $e;
        }

        $sandbox->close(null);

        return $result;
    }

    private static function open(Tenant $tenant, bool $fake_disks, Closure $waiting): self
    {
        self::refuse($tenant);

        $root = EvalFiles::folder(EvalFiles::root());
        $lock = fopen($root . '/.lock-' . $tenant->id, 'c');

        if ($lock === false) {
            throw new RuntimeException('Kan het slot ' . $root . '/.lock-' . $tenant->id . ' niet openen.');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $waiting();

            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Kan het slot op ' . $tenant->id . ' niet krijgen.');
            }
        }

        Mail::fake();
        Notification::fake();
        Queue::fake();

        return new self($lock, $fake_disks ? self::fakeDisks($root . '/.disks-' . $tenant->id) : null);
    }

    /**
     * Releases the lock whatever happens, then removes the run's files. A folder
     * that will not go is thrown about, unless the work already threw: then it
     * is reported, and the exception that ended the run is the one that is seen.
     */
    private function close(?Throwable $in_flight): void
    {
        flock($this->lock, LOCK_UN);
        fclose($this->lock);

        if ($this->disks === null || (new Filesystem)->deleteDirectory($this->disks)) {
            return;
        }

        $failure = new RuntimeException('Kan ' . $this->disks . ' niet opruimen; er staan bestanden van de run in.');

        if ($in_flight === null) {
            throw $failure;
        }

        report($failure);
    }

    /**
     * Runs the work in transactions that are always rolled back: on the
     * customer's database, and on the central one, where the meter writes what
     * every model call cost (assistant_usage) and the observers keep their
     * lookups. Left committed, an eval would eat into the customer's allowance.
     *
     * A deadlock makes InnoDB roll the whole transaction back on its own while
     * Laravel still counts it as open, and with autocommit on every statement
     * after it would land in the real database for good. With autocommit off
     * they open a new transaction instead, which the rollback here takes back.
     *
     * Autocommit is only switched back on after a rollback that worked; doing
     * so with a transaction still open would commit it. A connection that could
     * not roll back is closed instead, and the server throws away what it held.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function rolledBack(Closure $work): mixed
    {
        if (!tenancy()->initialized) {
            throw new RuntimeException('De eval en de probe draaien alleen binnen een klant, niet op de centrale database alleen.');
        }

        self::refuse(tenancy()->tenant);

        $connections = [];

        try {
            foreach ([DB::getDefaultConnection(), 'central'] as $name) {
                $connection = DB::connection($name);
                $connection->beginTransaction();
                $connections[] = $connection;
                $connection->statement('SET autocommit = 0');
            }

            $result = $work();
        } catch (Throwable $e) {
            self::rollBack($connections, $e);

            throw $e;
        }

        self::rollBack($connections, null);

        return $result;
    }

    /** @param  array<int, Connection>  $connections */
    private static function rollBack(array $connections, ?Throwable $in_flight): void
    {
        $failure = null;

        foreach ($connections as $connection) {
            try {
                $connection->rollBack();
                $connection->statement('SET autocommit = 1');
            } catch (Throwable $e) {
                DB::disconnect($connection->getName());
                $failure ??= $e;
            }
        }

        if ($failure !== null && $in_flight === null) {
            throw $failure;
        }

        if ($failure !== null) {
            report($failure);
        }
    }

    /**
     * Why the eval and the probe may not run on this customer, or null when they
     * may. They play with real data, so on a live installation they do not run
     * at all, and elsewhere only on a customer named in ASSISTANT_EVAL_TENANTS.
     *
     * The environment alone is no guard: the documented commands start with
     * APP_ENV=local, and copied onto a server that says "not production" to the
     * very installation it should keep them off. The list is a setting of the
     * machine itself, empty unless somebody put a customer on it there.
     */
    public static function refusal(Tenant $tenant): ?string
    {
        if (app()->isProduction()) {
            return 'De eval en de probe draaien niet in productie.';
        }

        $allowed = (array) config('assistant.evals.tenants', []);

        if (in_array((string) $tenant->getTenantKey(), $allowed, true) || in_array((string) $tenant->name, $allowed, true)) {
            return null;
        }

        return 'De eval en de probe draaien alleen op klanten die in ASSISTANT_EVAL_TENANTS staan (ids of namen, '
            . 'gescheiden door komma\'s), en ' . $tenant->name . ' staat daar niet in. Zet de klant in .env.local '
            . 'van deze machine; zie docs/development/assistant-tests.md.';
    }

    private static function refuse(Tenant $tenant): void
    {
        $reason = self::refusal($tenant);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    private static function fakeDisks(string $root): string
    {
        (new Filesystem)->deleteDirectory($root);

        foreach (self::DISKS as $disk) {
            Storage::set($disk, Storage::build([
                'driver' => 'local',
                'root' => EvalFiles::folder($root . '/' . $disk),
                'throw' => true,
            ]));
        }

        return $root;
    }
}
