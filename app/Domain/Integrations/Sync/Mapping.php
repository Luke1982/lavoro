<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Data\ExternalRecord;
use App\Domain\Integrations\Data\RecordData;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Everything the synchronizer needs to know about one area: how a model
 * becomes the standard shape and back, which connector method fetches or sends
 * it, and how an existing record here is recognised the first time.
 *
 * The rules of syncing itself -- fingerprints, who leads, what counts as gone --
 * are in Synchronizer and Merge, and the same for every area.
 */
abstract class Mapping
{
    /**
     * Ways to recognise an unlinked record here as the one from there, tried
     * in order: each a set of fields that must all be filled in and equal.
     *
     * @var array<int, array<int, string>>
     */
    protected const MATCH_ON = [];

    abstract public function area(): Area;

    /** @return array<int, string> */
    abstract public function fields(Connector $connector): array;

    abstract public function dataFrom(Model $model): RecordData;

    /** @return iterable<int, ExternalRecord> */
    abstract public function pull(Connector $connector, ?CarbonImmutable $since): iterable;

    abstract public function fetch(Connector $connector, string $id): ?ExternalRecord;

    abstract public function create(Connector $connector, RecordData $data): ExternalRecord;

    abstract public function update(Connector $connector, string $id, RecordData $data): ExternalRecord;

    /** A record without a name cannot become one here: every list shows it by its name. */
    public function accepts(RecordData $data): bool
    {
        return filled($data->name);
    }

    /**
     * Fills in $fields from $data, on $model or on a new record, and saves it
     * when anything changed. A value that may occur only once here -- a
     * material code -- and already belongs to another record stops the pull
     * with that said, instead of a database error.
     *
     * @param  array<int, string>  $fields
     */
    public function apply(RecordData $data, ?Model $model, array $fields): Model
    {
        $model ??= new ($this->area()->modelClass());
        $model->fill($this->values($data->only($fields)));

        if ($model->exists && !$model->isDirty()) {
            return $model;
        }

        try {
            $model->save();
        } catch (UniqueConstraintViolationException $e) {
            throw new Refusal(Str::ucfirst(mb_strtolower($this->area()->label())) . ': "' . $data->name . '" kan niet worden '
                . 'opgeslagen, want een ander record in Lavoro heeft al dezelfde code of hetzelfde nummer. '
                . 'Pas een van beide aan en haal opnieuw op.', 0, $e);
        }

        return $model;
    }

    /** The one unlinked record here that is plainly the same one, by MATCH_ON; never a guess between two. */
    public function match(RecordData $data, IntegrationConnection $connection): ?Model
    {
        foreach (static::MATCH_ON as $fields) {
            $where = $data->only($fields);

            if (in_array(null, $where, true)) {
                continue;
            }

            $found = $this->area()->modelClass()::notLinkedTo($connection)->where($where)->limit(2)->get();

            if ($found->count() === 1) {
                return $found->first();
            }
        }

        return null;
    }

    /**
     * What of the other side's values goes onto the record. A name stays when
     * they send none: it is required here, and an empty one there is a gap,
     * not a decision.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function values(array $values): array
    {
        if (!filled($values['name'] ?? null)) {
            unset($values['name']);
        }

        return $values;
    }
}
