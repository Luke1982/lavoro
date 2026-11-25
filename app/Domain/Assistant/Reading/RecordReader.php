<?php

namespace App\Domain\Assistant\Reading;

use App\Domain\Planning\Clock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One record, as this person may read it.
 *
 * Only when the policy's view allows it, and found through the kind's own
 * visibility scope as well, so a record outside someone's share is "not found"
 * rather than "not allowed" and its existence is not given away either.
 *
 * The fields are the table's own columns minus what Fields keeps back (hidden,
 * encrypted, or gated by a permission this person lacks), with each foreign key
 * named through $activity_relations where the record it points at is visible.
 */
final class RecordReader
{
    private const LONGEST_TEXT = 1500;

    /**
     * @param  class-string<Model>  $class
     * @return array<string, mixed>|null
     */
    public function read(string $class, int $id, User $user): ?array
    {
        if (!RecordKinds::mayRead($class, $user)) {
            return null;
        }

        $record = RecordKinds::query($class, $user)->whereKey($id)->first();

        if ($record === null || !$this->mayView($user, $record)) {
            return null;
        }

        $fields = new Fields($class, $user);
        $readable = $fields->readable();

        $record->makeVisible($fields->unlockedHidden())->setAppends([]);
        $values = array_intersect_key($record->attributesToArray(), array_flip($readable));

        foreach ($values as $column => $value) {
            $values[$column] = $this->shown($fields, $column, $record, $value);
        }

        $names = (new Conditions($class, $user))->namesOf($values);

        $result = [
            'soort' => RecordKinds::noun($class),
            'velden' => $values,
        ];

        if ($names !== []) {
            $result['namen'] = $names;
        }

        if ($page = RecordKinds::page($class, $id)) {
            $result['link'] = $page;
        }

        return $result;
    }

    /**
     * Where the kind has a visibility scope, the record was found through it, and
     * that scope selects exactly the records view allows (the rule of the read
     * layer, held by ReadLayerLeakTest): asking view would run it a second time.
     * Without a scope, the policy's view where it has one; without that, viewAny
     * is the whole rule and was already asked.
     */
    private function mayView(User $user, Model $record): bool
    {
        if (RecordKinds::hasVisibilityScope($record::class)) {
            return true;
        }

        $policy = Gate::getPolicyFor($record);

        return $policy === null || !method_exists($policy, 'view') || $user->can('view', $record);
    }

    private function shown(Fields $fields, string $column, Model $record, mixed $value): mixed
    {
        if ($fields->type($column) === 'moment' && $record->getRawOriginal($column) !== null) {
            return Clock::toLocal($record->getRawOriginal($column))->toDateTimeString();
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($value) && mb_strlen($value) > self::LONGEST_TEXT) {
            return mb_substr($value, 0, self::LONGEST_TEXT) . '…';
        }

        return $value;
    }
}
