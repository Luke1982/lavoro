<?php

namespace App\Domain\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * The short lists a row names by text -- brands, product types, categories,
 * units -- read once, so a row saying "bosch " finds Bosch without a query, and
 * a name that is not there yet can be told apart from one that is.
 */
class CatalogLookups
{
    /** @var array<string, array<int, string>> field => [id => name] */
    private array $names = [];

    /** @var array<string, array<string, int>> field => [lowercased name => id] */
    private array $ids = [];

    /**
     * @param  array<string, class-string<Model>>  $models  field => model
     */
    public function __construct(array $models)
    {
        foreach ($models as $field => $model) {
            $this->names[$field] = $model::query()->pluck('name', 'id')->all();
            $this->ids[$field] = [];

            foreach ($this->names[$field] as $id => $name) {
                $this->ids[$field][self::key($name)] = $id;
            }
        }
    }

    public function idFor(string $field, ?string $name): ?int
    {
        return $name === null ? null : ($this->ids[$field][self::key($name)] ?? null);
    }

    public function nameOf(string $field, ?int $id): ?string
    {
        return $id === null ? null : ($this->names[$field][$id] ?? null);
    }

    public static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
