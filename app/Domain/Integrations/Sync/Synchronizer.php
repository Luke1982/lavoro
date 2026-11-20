<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\RecordData;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\RecordNotFound;
use App\Exceptions\Refusal;
use App\Jobs\Integrations\PushRecordJob;
use App\Models\Integrationable;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Keeps records in step with a linked system, in both directions, for every
 * connector alike. This is the funnel: whatever the system, its records come
 * in through pull() and go out through push(), and the rules are here once.
 *
 * Each link keeps a fingerprint per field of both sides as they were at the
 * last sync, and Merge decides per field from those: what only they changed is
 * taken, what only we changed is sent, a clash is settled by Leading. A change
 * we just took is the same on both sides afterwards, so it is never sent back.
 *
 * A pull runs in one transaction, as the imports before it did: a failure
 * halfway leaves nothing half done. What it sends out goes on the queue and
 * only leaves after the commit.
 *
 * Nothing here talks to the other system inside a transaction: a pull fetches
 * first and then takes what it fetched. A token renewed on the way must be
 * committed the moment it arrives -- Exact kills the old refresh token at once,
 * so a rollback would cut the connection -- and a transaction should not stay
 * open while the network takes its time.
 */
class Synchronizer
{
    /** Longer than any pull may take (PullAreaJob::$timeout), so the lock never ends under one. */
    private const LOCK_SECONDS = 120;

    /** How long a send waits for a running pull before it gives up and is tried again later. A test sets it to 0. */
    public static int $wait_seconds = 15;

    /** @var array<string, true> The locks this process holds right now. */
    private static array $holding = [];

    public static function mapping(Area $area): Mapping
    {
        return match ($area) {
            Area::Customers => new CustomerMapping,
            Area::Materials => new MaterialMapping,
            Area::ServiceOrders => throw new InvalidArgumentException('Werkbonnen are sent, not synchronised.'),
        };
    }

    /**
     * Pulls an area in full, which also notices records removed there. With
     * $changes_only, a system that can tell is asked only what changed since
     * the last pull.
     */
    public function pull(IntegrationConnection $connection, Area $area, bool $changes_only = false): IntegrationRun
    {
        if (!$connection->reads($area)) {
            throw new Refusal($connection->label() . ' levert geen ' . mb_strtolower($area->label()) . ' aan Lavoro.');
        }

        $connector = $connection->connector();
        $since = $changes_only && $connector::changeDetection($area) === ChangeDetection::Delta
            ? $this->lastPulled($connection, $area)
            : null;

        return $this->exclusively($connection, $area, fn () => IntegrationRun::track(
            $connection,
            $area,
            $since === null ? IntegrationRun::PULL : IntegrationRun::PULL_CHANGES,
            null,
            function () use ($connection, $area, $connector, $since) {
                $mapping = self::mapping($area);
                $records = iterator_to_array($mapping->pull($connector, $since), false);

                return DB::transaction(fn () => $this->takeAll($connection, $mapping, $connector, $records, $since === null));
            },
        ));
    }

    /** One record from the other side, after a webhook named it. */
    public function pullOne(IntegrationConnection $connection, Area $area, string $external_id): string
    {
        if (!$connection->reads($area)) {
            return 'skipped';
        }

        return $this->exclusively($connection, $area, function () use ($connection, $area, $external_id) {
            $mapping = self::mapping($area);
            $connector = $connection->connector();
            $record = $mapping->fetch($connector, $external_id);

            if ($record === null) {
                $this->linkFor($connection, $area, $external_id)?->update(['missing_at' => now()]);

                return 'missing';
            }

            return DB::transaction(fn () => $this->take($connection, $mapping, $connector, $record));
        });
    }

    /**
     * Sends one record, new or changed. When this connection also reads the
     * area, their version is fetched first: if they changed it too, the two
     * are merged, and whatever of ours is left goes out from there.
     *
     * Returns what happened, for the run log: created, updated, merged,
     * unchanged, missing or skipped.
     */
    public function push(IntegrationConnection $connection, Area $area, Model $model): string
    {
        if (!$connection->writes($area)) {
            return 'skipped';
        }

        return $this->exclusively($connection, $area, fn () => $this->send($connection, $area, $model));
    }

    private function send(IntegrationConnection $connection, Area $area, Model $model): string
    {
        $mapping = self::mapping($area);
        $connector = $connection->connector();
        $fields = $mapping->fields($connector);
        $data = $mapping->dataFrom($model);
        $ours = $data->fingerprints($fields);
        $link = $model->integrationLink($connection);

        if (!$link?->confirmed_at && $connection->reads($area) && !$this->pulledInFull($connection, $area)) {
            throw new IntegrationUnavailable($connection->label() . ' is nog niet voor het eerst helemaal opgehaald. '
                . 'Lavoro stuurt ' . mb_strtolower($area->label()) . ' pas daarna, zodat wat daar al staat herkend wordt '
                . 'in plaats van dubbel aangemaakt.');
        }

        if (!$link) {
            return $this->create($connection, $mapping, $connector, $model, $data);
        }

        if ($link->missing_at) {
            return 'missing';
        }

        if (self::same($ours, $link->local_fingerprints)) {
            return 'unchanged';
        }

        if ($connection->reads($area) && $link->remote_fingerprints !== null) {
            $theirs = $mapping->fetch($connector, $link->external_id);

            if ($theirs === null) {
                $link->update(['missing_at' => now()]);

                return 'missing';
            }

            if (!self::same($theirs->data->fingerprints($fields), $link->remote_fingerprints)) {
                DB::transaction(fn () => $this->take($connection, $mapping, $connector, $theirs));

                return 'merged';
            }
        }

        try {
            $record = $mapping->update($connector, $link->external_id, $data);
        } catch (RecordNotFound) {
            if (!$link->confirmed_at) {
                $link->delete();

                return $this->create($connection, $mapping, $connector, $model, $data);
            }

            $link->update(['missing_at' => now()]);

            return 'missing';
        }

        $link->forceFill([
            'local_fingerprints' => $ours,
            'remote_fingerprints' => $record->data->fingerprints($fields),
            'confirmed_at' => $link->confirmed_at ?? now(),
            'synced_at' => now(),
        ])->save();

        return 'updated';
    }

    /**
     * A record that is not there yet. Also where a link that was never real
     * ends up -- a made-up SnelStart id, refused as unknown -- once it is gone.
     */
    private function create(IntegrationConnection $connection, Mapping $mapping, Connector $connector, Model $model, RecordData $data): string
    {
        $fields = $mapping->fields($connector);
        $record = $mapping->create($connector, $data);

        $this->link($connection, $model, $record->id)
            ->forceFill(['local_fingerprints' => $data->fingerprints($fields), 'remote_fingerprints' => $record->data->fingerprints($fields)])
            ->save();

        return 'created';
    }

    /**
     * The id there for a record here, sending it first when it is not linked
     * yet and this connection may create it. Null when it cannot be had.
     */
    public function ensureLinked(IntegrationConnection $connection, Area $area, Model $model): ?string
    {
        $link = $model->integrationLink($connection);

        if ($link?->missing_at) {
            return null;
        }

        if ($link?->confirmed_at || !$connection->writes($area)) {
            return $link?->external_id;
        }

        $this->push($connection, $area, $model);

        return $model->integrationLink($connection)?->external_id;
    }

    /**
     * @param  array<int, ExternalRecord>  $records
     * @param  bool  $complete  Everything there was fetched, so a link not among it is gone there.
     * @return array<string, int>
     */
    private function takeAll(IntegrationConnection $connection, Mapping $mapping, Connector $connector, array $records, bool $complete): array
    {
        $counts = [];

        foreach ($records as $record) {
            $outcome = $this->take($connection, $mapping, $connector, $record);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $seen = array_map(fn (ExternalRecord $record) => $record->id, $records);

        return $complete ? $counts + $this->noteMissing($connection, $mapping->area(), $seen) : $counts;
    }

    /**
     * One record from the other side: made here, recognised here, or merged
     * into the one it is linked to. Returns the outcome for the counts.
     */
    private function take(IntegrationConnection $connection, Mapping $mapping, Connector $connector, ExternalRecord $record): string
    {
        $area = $mapping->area();
        $fields = $mapping->fields($connector);
        $theirs = $record->data->fingerprints($fields);
        $link = $this->linkFor($connection, $area, $record->id);

        if ($link && !$link->integrationable) {
            $link->delete();
            $link = null;
        }

        $outcome = null;

        if (!$link) {
            if (!$mapping->accepts($record->data)) {
                return 'skipped';
            }

            $existing = $mapping->match($record->data, $connection);

            if (!$existing) {
                $model = $mapping->apply($record->data, null, $fields);
                $this->link($connection, $model, $record->id)
                    ->forceFill(['local_fingerprints' => $mapping->dataFrom($model)->fingerprints($fields), 'remote_fingerprints' => $theirs])
                    ->save();

                return 'created';
            }

            $existing->integrationLink($connection)?->delete();
            $link = $this->link($connection, $existing, $record->id);
            $outcome = 'linked';
        }

        $model = $link->integrationable;
        $merge = Merge::decide(
            $mapping->dataFrom($model)->fingerprints($fields),
            $theirs,
            $link->local_fingerprints,
            $link->remote_fingerprints,
            $connection->leading($area),
        );

        if ($merge->take !== []) {
            $model = $mapping->apply($record->data, $model, $merge->take);
        }

        $link->forceFill([
            'local_fingerprints' => $merge->oursBaseline($mapping->dataFrom($model)->fingerprints($fields), $theirs, $link->local_fingerprints),
            'remote_fingerprints' => $theirs,
            'confirmed_at' => $link->confirmed_at ?? now(),
            'missing_at' => null,
        ]);

        if ($merge->take !== [] || $merge->keep !== []) {
            $link->synced_at = now();
        }

        if ($link->isDirty()) {
            $link->save();
        }

        if ($merge->keep !== [] && $connection->writes($area)) {
            PushRecordJob::dispatch($connection->id, $area->value, $model->getKey())->afterCommit();
        }

        return $outcome ?? $merge->outcome();
    }

    /**
     * After a complete pull: a link whose record was not in it. Never
     * confirmed means it never existed there, and the link goes; confirmed
     * means it was removed there, and the record is left alone from now on.
     *
     * An empty pull marks nothing. A system that suddenly answers with no
     * records at all is far likelier to be broken than emptied.
     *
     * @param  array<int, string>  $seen
     * @return array<string, int>
     */
    private function noteMissing(IntegrationConnection $connection, Area $area, array $seen): array
    {
        if ($seen === []) {
            return [];
        }

        $links = Integrationable::where('integration_connection_id', $connection->id)
            ->where('integrationable_type', $area->morphClass())
            ->whereNull('missing_at')
            ->whereNotIn('external_id', $seen);

        return [
            'unlinked' => (clone $links)->whereNull('confirmed_at')->delete(),
            'missing' => (clone $links)->whereNotNull('confirmed_at')->update(['missing_at' => now()]),
        ];
    }

    /** A new link, confirmed: the other side has just shown it has the record. Not saved yet. */
    private function link(IntegrationConnection $connection, Model $model, string $external_id): Integrationable
    {
        return (new Integrationable)->forceFill([
            'integration_connection_id' => $connection->id,
            'integrationable_type' => $model->getMorphClass(),
            'integrationable_id' => $model->getKey(),
            'external_id' => $external_id,
            'confirmed_at' => now(),
            'synced_at' => now(),
        ])->setRelation('integrationable', $model);
    }

    private function linkFor(IntegrationConnection $connection, Area $area, string $external_id): ?Integrationable
    {
        return Integrationable::where('integration_connection_id', $connection->id)
            ->where('integrationable_type', $area->morphClass())
            ->where('external_id', $external_id)
            ->first();
    }

    /**
     * One sync at a time per connection and area. Without it, a customer just
     * created there can come back through its webhook before the link is
     * saved, and be made here a second time.
     *
     * Re-entrant within this process: a pull that sends something on, on a
     * queue that runs jobs straight away, already holds the lock it needs.
     */
    private function exclusively(IntegrationConnection $connection, Area $area, Closure $work): mixed
    {
        $key = 'integration-sync:' . $connection->id . ':' . $area->value;

        if (isset(self::$holding[$key])) {
            return $work();
        }

        try {
            return Cache::lock($key, self::LOCK_SECONDS)->block(self::$wait_seconds, function () use ($key, $work) {
                self::$holding[$key] = true;

                try {
                    return $work();
                } finally {
                    unset(self::$holding[$key]);
                }
            });
        } catch (LockTimeoutException) {
            throw new IntegrationUnavailable('Er loopt al een synchronisatie met ' . $connection->label()
                . '. Probeer het zo opnieuw.');
        }
    }

    /**
     * A little before the last pull of the area, so a change made while it ran
     * is not missed. A pull after a webhook does not count: it fetched one
     * record and says nothing about the others.
     */
    private function lastPulled(IntegrationConnection $connection, Area $area): ?CarbonImmutable
    {
        $last = $this->pulls($connection, $area, [IntegrationRun::PULL, IntegrationRun::PULL_CHANGES])->max('started_at');

        return $last ? CarbonImmutable::parse($last)->subMinutes(5) : null;
    }

    /** Whether everything there was compared at least once, so whatever is still unlinked is really not there. */
    public function pulledInFull(IntegrationConnection $connection, Area $area): bool
    {
        return $this->pulls($connection, $area, [IntegrationRun::PULL])->exists();
    }

    /** @param  array<int, string>  $directions */
    private function pulls(IntegrationConnection $connection, Area $area, array $directions): HasMany
    {
        return $connection->runs()
            ->where('area', $area->value)
            ->whereIn('direction', $directions)
            ->where('status', IntegrationRun::OK);
    }

    /**
     * Strict, and regardless of key order. Not ==: two hex fingerprints that
     * both look like "0e..." are equal numbers to PHP's loose comparison.
     *
     * @param  array<string, string>|null  $a
     * @param  array<string, string>|null  $b
     */
    private static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        ksort($a);
        ksort($b);

        return $a === $b;
    }
}
