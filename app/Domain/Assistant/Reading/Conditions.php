<?php

namespace App\Domain\Assistant\Reading;

use App\Domain\Planning\Clock;
use App\Domain\Search\SearchTerm;
use App\Domain\Tools\ToolCall;
use App\Exceptions\Refusal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns the assistant's conditions into a query, and answers it in the database.
 *
 * The shape is fixed and never names a method:
 *
 *   [{"field": "order_date", "op": "<", "value": "2026-10-25"},
 *    {"has": "events", "not": true},
 *    {"field": "customer.city", "op": "=", "value": "Tiel"}]
 *
 * Every field is checked against the table and against what this person may
 * read (Fields), every relation against the model's real relation methods and
 * the related kind's own visibility. The count, the sum and the grouping run on
 * the kind's visible records (RecordKinds::query); an overview page applies the
 * same conditions on top of its own query, which follows the same policy and
 * scope. With ?filter= that page drops the defaults that would narrow it (a list
 * that opens on the running projects shows every status), so it opens on the
 * records that were counted; whatever somebody narrows further by hand, the bar
 * above the list says so.
 *
 * A refusal is a Refusal with a Dutch sentence the model can act on: which field
 * does not exist and which ones do, which operator, which date format.
 */
final class Conditions
{
    public const OPS = ['=', '!=', '<', '>', '<=', '>=', 'in', 'like', 'empty', 'filled'];

    private const MAX_CONDITIONS = 10;

    private const MAX_IN = 200;

    private const MAX_GROUPS = 50;

    /** The ways a yes or a no may be written, for a ja/nee field and for "not". */
    private const YES = [true, 1, '1', 'true', 'ja'];

    private const NO = [false, 0, '0', 'false', 'nee'];

    private Fields $fields;

    /** @param  class-string<Model>  $class */
    public function __construct(public readonly string $class, private readonly User $user)
    {
        $this->fields = new Fields($class, $user);
    }

    /** The visible records of this kind that meet every condition. */
    public function query(array $conditions): Builder
    {
        return $this->apply(RecordKinds::query($this->class, $this->user), $conditions);
    }

    /**
     * Narrows a query somebody else built: an overview page's, after its own rules.
     *
     * @param  array<int, mixed>  $conditions
     */
    public function apply(Builder $query, array $conditions): Builder
    {
        if (!array_is_list($conditions)) {
            throw new Refusal('Geef de voorwaarden als een lijst: [{"field": ..., "op": ..., "value": ...}].');
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            throw new Refusal('Hoogstens ' . self::MAX_CONDITIONS . ' voorwaarden tegelijk.');
        }

        foreach ($conditions as $condition) {
            $this->applyOne($query, $condition);
        }

        return $query;
    }

    public function count(Builder $query): int
    {
        return (clone $query)->count();
    }

    /** The total of one numeric field over every matching record. */
    public function sum(Builder $query, string $field): float|int
    {
        $this->checkSummable($field);

        $total = (clone $query)->sum($query->qualifyColumn($field));

        return $total + 0;
    }

    /**
     * How many there are per value of one field, largest first, with the total of
     * a numeric field per group when asked. A foreign key comes back named.
     *
     * @return array{groups: array<int, array<string, mixed>>, more: bool}
     */
    public function group(Builder $query, string $field, ?string $sum = null): array
    {
        $this->fields->check($field);

        if ($this->fields->type($field) === 'moment') {
            throw new Refusal('Groeperen op een tijdstip ("' . $field . '") geeft één groep per seconde. Kies een ander veld.');
        }

        if ($this->fields->isUnique($field)) {
            throw new Refusal('Groeperen op een uniek veld ("' . $field . '") geeft een lijst — gebruik link.');
        }

        if ($sum !== null) {
            $this->checkSummable($sum);
        }

        $qualified = $query->qualifyColumn($field);
        $grammar = $query->getQuery()->getGrammar();

        $rows = (clone $query)->reorder()->toBase()
            ->select([$qualified . ' as value'])
            ->selectRaw('count(*) as aantal')
            ->when($sum !== null, fn ($q) => $q->selectRaw('sum(' . $grammar->wrap($query->qualifyColumn($sum)) . ') as totaal'))
            ->groupBy($qualified)
            ->orderByDesc('aantal')
            ->limit(self::MAX_GROUPS + 1)
            ->get();

        $more = $rows->count() > self::MAX_GROUPS;
        $rows = $rows->take(self::MAX_GROUPS);

        $names = $this->namesFor($field, $rows->pluck('value')->filter(fn ($value) => $value !== null)->all());

        $groups = $rows->map(function ($row) use ($field, $names, $sum) {
            $group = [$field => $row->value];

            if (isset($names[$row->value])) {
                $group['naam'] = $names[$row->value];
            }

            $group['aantal'] = (int) $row->aantal;

            if ($sum !== null) {
                $group['totaal_' . $sum] = $row->totaal === null ? 0 : $row->totaal + 0;
            }

            return $group;
        })->values()->all();

        return ['groups' => $groups, 'more' => $more];
    }

    /**
     * The names of the records some foreign key values point at, for the ones
     * this person may see; the others stay a number.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, string>
     */
    public function namesFor(string $field, array $ids): array
    {
        $source = $this->fields->nameSource($field);

        if ($source === null || $ids === []) {
            return [];
        }

        [$relation_name, $display] = $source;
        $relation = Fields::relationOf($this->class, $relation_name);

        if ($relation === null) {
            return [];
        }

        $related = $relation->getRelated()::class;

        if (!$this->mayName($related, $display)) {
            return [];
        }

        $owner_key = method_exists($relation, 'getOwnerKeyName') ? $relation->getOwnerKeyName() : $relation->getRelated()->getKeyName();

        return RecordKinds::query($related, $this->user)
            ->whereIn($relation->getRelated()->qualifyColumn($owner_key), $ids)
            ->get()
            ->mapWithKeys(fn (Model $model) => [$model->getAttribute($owner_key) => (string) $model->getAttribute($display)])
            ->all();
    }

    /**
     * The names behind the foreign keys of one record, as column => name, with
     * one query per kind of record pointed at rather than one per column: a
     * werkbon's creator, closer and planner are all medewerkers.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function namesOf(array $values): array
    {
        $wanted = [];

        foreach ($this->fields->nameSources() as $column => [$relation_name, $display]) {
            if (!isset($values[$column]) || !is_scalar($values[$column])) {
                continue;
            }

            $relation = Fields::relationOf($this->class, $relation_name);

            if ($relation === null) {
                continue;
            }

            $related = $relation->getRelated()::class;
            $owner_key = method_exists($relation, 'getOwnerKeyName') ? $relation->getOwnerKeyName() : $relation->getRelated()->getKeyName();
            $wanted[$related . '|' . $owner_key . '|' . $display][$column] = $values[$column];
        }

        $names = [];

        foreach ($wanted as $group => $columns) {
            [$related, $owner_key, $display] = explode('|', $group);

            if (!$this->mayName($related, $display)) {
                continue;
            }

            $found = RecordKinds::query($related, $this->user)
                ->whereIn((new $related)->qualifyColumn($owner_key), array_values(array_unique($columns)))
                ->get()
                ->mapWithKeys(fn (Model $model) => [$model->getAttribute($owner_key) => (string) $model->getAttribute($display)]);

            foreach ($columns as $column => $value) {
                if (isset($found[$value])) {
                    $names[$column] = $found[$value];
                }
            }
        }

        return $names;
    }

    /**
     * The sentence shown above an overview page, made from the conditions
     * themselves so it can never say something the filter does not do.
     */
    public function describe(array $conditions): string
    {
        $parts = array_map(fn ($condition) => $this->describeOne($condition), $conditions);

        $plural = ucfirst(RecordKinds::plural($this->class));

        if ($parts === []) {
            return $plural;
        }

        $last = array_pop($parts);

        return $plural . ' ' . ($parts === [] ? $last : implode(', ', $parts) . ' en ' . $last);
    }

    private function applyOne(Builder $query, mixed $condition): void
    {
        if (!is_array($condition) || array_is_list($condition)) {
            throw new Refusal('Elke voorwaarde is een object, bijvoorbeeld {"field": "city", "op": "=", "value": "Tiel"} of {"has": "events", "not": true}.');
        }

        $unknown = array_diff(array_keys($condition), ['field', 'op', 'value', 'has', 'not']);

        if ($unknown !== []) {
            throw new Refusal('Onbekend in een voorwaarde: ' . implode(', ', $unknown) . '. Gebruik field, op en value, of has en not.');
        }

        if (isset($condition['has'])) {
            $this->applyHas($query, $condition);

            return;
        }

        if (array_key_exists('not', $condition)) {
            throw new Refusal('"not" hoort alleen bij "has". Gebruik voor een veld "!=" of "empty".');
        }

        if (!isset($condition['field']) || !is_string($condition['field'])) {
            throw new Refusal('Een voorwaarde heeft een "field" of een "has" nodig.');
        }

        $op = $this->op($condition);
        $value = $condition['value'] ?? null;

        if (str_contains($condition['field'], '.')) {
            [$relation_name, $column] = $this->splitRelationField($condition['field']);
            $relation = $this->fields->relation($relation_name);
            $related = $relation->getRelated()::class;
            $related_fields = new Fields($related, $this->user);
            $related_fields->check($column);

            $this->validateValue($related_fields, $column, $op, $value);

            $query->whereHas($relation_name, function (Builder $inner) use ($related_fields, $column, $op, $value) {
                RecordKinds::constrain($inner, $this->user);
                $this->compare($inner, $related_fields, $column, $op, $value);
            });

            return;
        }

        $this->fields->check($condition['field']);
        $this->validateValue($this->fields, $condition['field'], $op, $value);
        $this->compare($query, $this->fields, $condition['field'], $op, $value);
    }

    private function applyHas(Builder $query, array $condition): void
    {
        if (!is_string($condition['has']) || isset($condition['field']) || isset($condition['op'])) {
            throw new Refusal('Gebruik {"has": "<relatie>"} los, met "not": true voor "heeft er geen".');
        }

        $this->fields->relation($condition['has']);
        $visible = fn (Builder $inner) => RecordKinds::constrain($inner, $this->user);

        $not = self::yesOrNo($condition['not'] ?? false);

        if ($not === null) {
            throw new Refusal('"not" is ja of nee; geef true of false.');
        }

        if ($not) {
            $query->whereDoesntHave($condition['has'], $visible);
        } else {
            $query->whereHas($condition['has'], $visible);
        }
    }

    private function op(array $condition): string
    {
        $op = $condition['op'] ?? '=';

        if (!is_string($op) || !in_array(strtolower(trim($op)), self::OPS, true)) {
            throw new Refusal('Onbekende vergelijking "' . (is_scalar($op) ? $op : '?') . '". Kies uit: ' . implode(' ', self::OPS) . '.');
        }

        return strtolower(trim($op));
    }

    /** @return array{0: string, 1: string} */
    private function splitRelationField(string $field): array
    {
        $parts = explode('.', $field);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new Refusal('Een veld via een relatie is relatie.kolom, één niveau diep, zoals customer.city.');
        }

        return [$parts[0], $parts[1]];
    }

    private function validateValue(Fields $fields, string $column, string $op, mixed $value): void
    {
        if (in_array($op, ['empty', 'filled'], true)) {
            return;
        }

        if ($op === 'in') {
            if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_IN) {
                throw new Refusal('Bij "in" hoort een lijst van 1 tot ' . self::MAX_IN . ' waarden.');
            }

            foreach ($value as $entry) {
                $this->validateScalar($fields, $column, $op, $entry);
            }

            return;
        }

        $this->validateScalar($fields, $column, $op, $value);
    }

    private function validateScalar(Fields $fields, string $column, string $op, mixed $value): void
    {
        if (!is_scalar($value)) {
            throw new Refusal('Geef bij "' . $column . '" één waarde, of gebruik "in" voor een lijst.');
        }

        $type = $fields->type($column);

        if ($op === 'like' && in_array($type, ['date', 'moment', 'number', 'boolean'], true)) {
            throw new Refusal('"like" is voor tekst; "' . $column . '" is geen tekstveld.');
        }

        if (in_array($type, ['date', 'moment'], true) && ToolCall::parseDate((string) $value) === null) {
            throw new Refusal('Geef "' . $column . '" als een echte datum in de vorm JJJJ-MM-DD.');
        }

        if ($type === 'moment' && $op === 'in') {
            throw new Refusal('"in" kan niet op een tijdstip ("' . $column . '"); gebruik >= en < met datums.');
        }

        if ($type === 'number' && (!is_numeric($value) || !is_finite((float) $value))) {
            throw new Refusal('"' . $column . '" is een getal; geef een getal.');
        }

        if ($type === 'boolean' && self::yesOrNo($value) === null) {
            throw new Refusal('"' . $column . '" is ja of nee; geef true of false.');
        }
    }

    private function compare(Builder $query, Fields $fields, string $column, string $op, mixed $value): void
    {
        $qualified = $query->qualifyColumn($column);
        $type = $fields->type($column);

        if ($op === 'empty' || $op === 'filled') {
            $empty = fn (Builder $q) => $type === 'text'
                ? $q->whereNull($qualified)->orWhere($qualified, '')
                : $q->whereNull($qualified);

            $op === 'empty'
                ? $query->where($empty)
                : $query->whereNot($empty);

            return;
        }

        if ($type === 'moment') {
            $this->compareMoment($query, $qualified, $op, ToolCall::parseDate((string) $value));

            return;
        }

        /**
         * Text is compared as text: MySQL compares a text column with a number
         * by turning every value into a number, and "abc" = 0 then matches.
         */
        $cast = fn ($entry) => match ($type) {
            'boolean' => self::yesOrNo($entry) ? 1 : 0,
            'number' => $entry + 0,
            default => is_bool($entry) ? (string) (int) $entry : (string) $entry,
        };

        match ($op) {
            'in' => $query->whereIn($qualified, array_map($cast, $value)),
            'like' => $query->where($qualified, 'like', SearchTerm::like((string) $value)),
            '!=' => $query->where(fn (Builder $q) => $q->where($qualified, '!=', $cast($value))->orWhereNull($qualified)),
            default => $query->where($qualified, $op, $cast($value)),
        };
    }

    /**
     * A day compared with a stored moment, on the local clock: "before the 25th"
     * is before midnight here, not before midnight UTC, or two hours of the
     * evening before land on the wrong side.
     */
    private function compareMoment(Builder $query, string $qualified, string $op, \DateTimeInterface $day): void
    {
        $start = Clock::startOfLocalDay($day);
        $next = Clock::startOfLocalDay(CarbonImmutable::instance($day)->addDay());

        match ($op) {
            '=' => $query->where($qualified, '>=', $start)->where($qualified, '<', $next),
            '!=' => $query->where(fn (Builder $q) => $q->where($qualified, '<', $start)->orWhere($qualified, '>=', $next)->orWhereNull($qualified)),
            '<' => $query->where($qualified, '<', $start),
            '<=' => $query->where($qualified, '<', $next),
            '>' => $query->where($qualified, '>=', $next),
            '>=' => $query->where($qualified, '>=', $start),
        };
    }

    /**
     * Whether this person may see records of the related kind named by this
     * column: a column of its table goes through that kind's Fields like any
     * other read; a name made by an accessor (a contact's full name) has no
     * column of its own to refuse.
     */
    private function mayName(string $related, string $display): bool
    {
        if (!RecordKinds::mayRead($related, $this->user)) {
            return false;
        }

        $fields = new Fields($related, $this->user);

        return !isset($fields->columns()[$display]) || $fields->refusal($display) === null;
    }

    /** True for a yes, false for a no, null for anything that is neither. */
    private static function yesOrNo(mixed $value): ?bool
    {
        $value = is_string($value) ? strtolower(trim($value)) : $value;

        return match (true) {
            in_array($value, self::YES, true) => true,
            in_array($value, self::NO, true) => false,
            default => null,
        };
    }

    private function checkSummable(string $field): void
    {
        $this->fields->check($field);

        if ($this->fields->type($field) !== 'number') {
            throw new Refusal('Optellen kan alleen met een getalveld; "' . $field . '" is dat niet.');
        }
    }

    private function describeOne(array $condition): string
    {
        if (isset($condition['has'])) {
            $relation = Fields::relationOf($this->class, $condition['has']);
            $noun = $relation ? RecordKinds::noun($relation->getRelated()::class) : $condition['has'];

            return (self::yesOrNo($condition['not'] ?? false) ? 'zonder ' : 'met ') . $noun;
        }

        $field = $condition['field'];
        $fields = $this->fields;
        $naming = $this;
        $prefix = '';

        if (str_contains($field, '.')) {
            [$relation_name, $field] = explode('.', $field, 2);
            $relation = Fields::relationOf($this->class, $relation_name);

            if ($relation !== null) {
                $fields = new Fields($relation->getRelated()::class, $this->user);
                $naming = new self($relation->getRelated()::class, $this->user);
                $prefix = RecordKinds::noun($relation->getRelated()::class) . ' ';
            }
        }

        $op = strtolower(trim($condition['op'] ?? '='));
        $type = $fields->type($field);
        $label = $prefix . mb_strtolower($fields->label($field));

        $value = $condition['value'] ?? null;
        $values = is_array($value) ? $value : [$value];
        $names = $naming->namesFor($field, array_values(array_filter($values, fn ($entry) => is_int($entry) || (is_string($entry) && ctype_digit($entry)))));
        $shown = implode(', ', array_map(
            fn ($entry) => is_scalar($entry) && isset($names[$entry]) ? '"' . $names[$entry] . '"' : $this->shown($entry, $type),
            $values,
        ));

        $dated = in_array($type, ['date', 'moment'], true);

        $words = match ($op) {
            '=' => $dated ? 'op' : 'is',
            '!=' => $dated ? 'niet op' : 'is niet',
            '<' => $dated ? 'vóór' : 'kleiner dan',
            '>' => $dated ? 'na' : 'groter dan',
            '<=' => $dated ? 'op of vóór' : 'hoogstens',
            '>=' => $dated ? 'op of na' : 'minstens',
            'in' => 'is een van',
            'like' => 'bevat',
            'empty' => 'is leeg',
            'filled' => 'is ingevuld',
            default => $op,
        };

        return 'waarbij ' . $label . ' ' . $words . (in_array($op, ['empty', 'filled'], true) ? '' : ' ' . $shown);
    }

    private function shown(mixed $value, string $type): string
    {
        if (in_array($type, ['date', 'moment'], true) && ($date = ToolCall::parseDate((string) $value)) !== null) {
            return $date->format('d-m-Y');
        }

        if ($type === 'boolean') {
            return self::yesOrNo($value) ? 'ja' : 'nee';
        }

        return '"' . (is_scalar($value) ? (string) $value : '') . '"';
    }
}
