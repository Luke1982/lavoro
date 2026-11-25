<?php

namespace App\Domain\Assistant\Reading;

use App\Exceptions\Refusal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Which columns of one kind one person may read, and what they mean.
 *
 * The same maps the timeline uses decide it, so a field the timeline hides from
 * somebody is a field the assistant cannot read, count or filter on for them:
 *
 * - a password, a token and anything stored encrypted is never read, whatever
 *   permission someone holds;
 * - a column in `$activity_permissions` needs that permission, whether or not the
 *   model also lists it in `$hidden` (that only keeps it out of JSON that travels
 *   to pages without the check);
 * - any other column in `$hidden` is never read;
 * - a foreign key is named through `$activity_relations`.
 */
final class Fields
{
    private const NUMERIC = ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'numeric', 'float', 'double', 'real'];

    private const NEVER = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** @var array<string, bool> */
    private static array $returns_a_relation = [];

    private Model $model;

    /** @param  class-string<Model>  $class */
    public function __construct(public readonly string $class, private readonly User $user)
    {
        $this->model = new $class;
    }

    /**
     * Every column of the table with its database type.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return app(TableColumns::class)->of($this->model->getConnectionName(), $this->model->getTable());
    }

    /** Whether the column is unique on its own, so that grouping on it gives one group per record. */
    public function isUnique(string $column): bool
    {
        return in_array($column, app(TableColumns::class)->uniqueOf($this->model->getConnectionName(), $this->model->getTable()), true);
    }

    /**
     * The columns this person may read.
     *
     * @return array<int, string>
     */
    public function readable(): array
    {
        return array_values(array_filter(array_keys($this->columns()), fn (string $column) => $this->refusal($column) === null));
    }

    /** Null when the column may be read, otherwise why not, in words the model can act on. */
    public function refusal(string $column): ?string
    {
        $noun = RecordKinds::noun($this->class);

        if (!isset($this->columns()[$column])) {
            return 'Een ' . $noun . ' heeft geen veld "' . $column . '". Velden: ' . implode(', ', $this->readable()) . '.';
        }

        $unreadable = 'Het veld "' . $column . '" van een ' . $noun . ' is niet te lezen.';

        if (in_array($column, self::NEVER, true) || $this->isEncrypted($column)) {
            return $unreadable;
        }

        $permission = $this->permissionFor($column);

        if ($permission !== null) {
            return $this->user->hasPermission($permission)
                ? null
                : 'Het veld "' . $column . '" van een ' . $noun . ' mag deze gebruiker niet zien.';
        }

        return in_array($column, $this->model->getHidden(), true) ? $unreadable : null;
    }

    public function check(string $column): void
    {
        if (($refusal = $this->refusal($column)) === null) {
            return;
        }

        throw $this->isClosedByPermission($column) ? new ClosedToThisPerson($refusal) : new Refusal($refusal);
    }

    /** Whether the column is there to be read, only not by this person. */
    private function isClosedByPermission(string $column): bool
    {
        $permission = isset($this->columns()[$column]) ? $this->permissionFor($column) : null;

        return $permission !== null && !$this->user->hasPermission($permission);
    }

    /** Hidden columns this person may read after all, because their permission says so. */
    public function unlockedHidden(): array
    {
        return array_values(array_filter(
            $this->model->getHidden(),
            fn (string $column) => $this->permissionFor($column) !== null && $this->refusal($column) === null,
        ));
    }

    public function type(string $column): string
    {
        $cast = $this->model->getCasts()[$column] ?? null;

        if (in_array($cast, ['bool', 'boolean'], true)) {
            return 'boolean';
        }

        $type = $this->columns()[$column] ?? 'varchar';

        return match (true) {
            $type === 'date' => 'date',
            in_array($type, ['datetime', 'timestamp'], true) => 'moment',
            in_array($type, self::NUMERIC, true) => 'number',
            default => 'text',
        };
    }

    /** The readable name of a column, as the timeline labels it. */
    public function label(string $column): string
    {
        return $this->map('activity_labels')[$column] ?? str_replace('_', ' ', $column);
    }

    /**
     * The relation and display column a foreign key is named by, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function nameSource(string $column): ?array
    {
        return $this->nameSources()[$column] ?? null;
    }

    /**
     * Every foreign key named through `$activity_relations`, as column => [relation,
     * display column]. The same map also holds formatters (`[Money::class, 'euros']`)
     * that turn a value into text; those name no record and are left out.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function nameSources(): array
    {
        $sources = [];

        foreach ($this->map('activity_relations') as $column => $source) {
            if (!is_array($source) || count($source) !== 2) {
                continue;
            }

            [$relation, $display] = array_values($source);

            if (is_string($relation) && is_string($display) && !class_exists($relation) && method_exists($this->model, $relation)) {
                $sources[$column] = [$relation, $display];
            }
        }

        return $sources;
    }

    /**
     * A real Eloquent relation method of the model, to a kind this person may read.
     *
     * Only a method that declares a relation as its return type, or whose body
     * returns one of Eloquent's relation builders, is ever called: the name comes
     * from a language model, and a method like save() or delete() must never run
     * because it was spelled as a relation.
     */
    public function relation(string $name): Relation
    {
        $noun = RecordKinds::noun($this->class);
        $relation = self::relationOf($this->class, $name);

        if ($relation === null) {
            $names = $this->relationNames();

            throw new Refusal('Een ' . $noun . ' heeft geen relatie "' . $name . '". '
                . ($names === [] ? 'Er zijn geen relaties die deze gebruiker kan volgen.' : 'Relaties: ' . implode(', ', $names) . '.'));
        }

        $related = $relation->getRelated()::class;

        if (!RecordKinds::mayRead($related, $this->user)) {
            throw new ClosedToThisPerson('Via "' . $name . '" kom je bij ' . RecordKinds::noun($related) . ', en die mag deze gebruiker niet lezen.');
        }

        return $relation;
    }

    /**
     * The relations of this kind that lead to a kind this person may read.
     *
     * @return array<int, string>
     */
    public function relationNames(): array
    {
        $names = [];

        foreach ((new \ReflectionClass($this->class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                continue;
            }

            $relation = self::relationOf($this->class, $method->getName());

            if ($relation !== null && RecordKinds::mayRead($relation->getRelated()::class, $this->user)) {
                $names[] = $method->getName();
            }
        }

        sort($names);

        return $names;
    }

    /** @param  class-string<Model>  $class */
    public static function relationOf(string $class, string $name): ?Relation
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name) || !method_exists($class, $name)) {
            return null;
        }

        $method = new \ReflectionMethod($class, $name);

        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0
            || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
            return null;
        }

        $type = $method->getReturnType();
        $declared = $type instanceof \ReflectionNamedType && is_a($type->getName(), Relation::class, true);

        if (!$declared && !self::bodyReturnsARelation($method)) {
            return null;
        }

        $relation = (new $class)->{$name}();

        return $relation instanceof Relation && !$relation instanceof MorphTo ? $relation : null;
    }

    /** Read from the source once per method and process: the code does not change between requests. */
    private static function bodyReturnsARelation(\ReflectionMethod $method): bool
    {
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();

        if (isset(self::$returns_a_relation[$key])) {
            return self::$returns_a_relation[$key];
        }

        $file = $method->getFileName();

        if ($file === false) {
            return self::$returns_a_relation[$key] = false;
        }

        $lines = array_slice(file($file), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

        return self::$returns_a_relation[$key] = preg_match(
            '/return\s+\$this\s*->\s*(hasOne|hasMany|belongsTo|belongsToMany|morphOne|morphMany|morphToMany|morphedByMany|hasManyThrough|hasOneThrough)\s*\(/',
            implode('', $lines),
        ) === 1;
    }

    /**
     * The columns behind a permission, as column => permission.
     *
     * @return array<string, string>
     */
    public function gated(): array
    {
        return $this->map('activity_permissions');
    }

    private function permissionFor(string $column): ?string
    {
        return $this->map('activity_permissions')[$column] ?? null;
    }

    /** Cast `encrypted`, `encrypted:array`, ..., or with one of Eloquent's AsEncrypted* classes. */
    private function isEncrypted(string $column): bool
    {
        $cast = $this->model->getCasts()[$column] ?? null;

        if (!is_string($cast)) {
            return false;
        }

        $cast = explode(':', $cast, 2)[0];

        return str_starts_with($cast, 'encrypted') || str_starts_with(class_basename($cast), 'AsEncrypted');
    }

    /** A protected map off the model, read the way RecordsHistory reads it. */
    private function map(string $property): array
    {
        $model = $this->model;

        $read = function () use ($property) {
            return property_exists($this, $property) && is_array($this->{$property}) ? $this->{$property} : [];
        };

        return \Closure::bind($read, $model, $model::class)();
    }
}
