<?php

namespace App\Models;

use App\Traits\HasParent;
use Database\Factories\ProductTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    /** @use HasFactory<ProductTypeFactory> */
    use HasFactory;

    use HasParent;

    /**
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'typical_certificate_days',
        'parent_id',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /** Returns [ typeId => 'Grandparent → Parent → Name' ] for every type. */
    public static function pathMap(): array
    {
        $all = static::orderBy('name')->get()->keyBy('id');
        $paths = [];
        foreach ($all as $type) {
            $path = [$type->name];
            $current = $type;
            while ($current->parent_id && isset($all[$current->parent_id])) {
                $current = $all[$current->parent_id];
                array_unshift($path, $current->name);
            }
            $paths[$type->id] = implode(' → ', $path);
        }

        return $paths;
    }

    /**
     * Every type's parent, by id: the whole tree in one query, for walking it
     * up or down without a query per step.
     *
     * @return array<int, ?int>
     */
    public static function parentIds(): array
    {
        return static::query()->pluck('parent_id', 'id')->all();
    }

    /**
     * The type itself first, then each parent up to the root. A machine of a
     * subtype is a machine of every type above it, which is how a price formula
     * filtering on a type counts it.
     *
     * @param  ?array<int, ?int>  $parents  parentIds(), when the caller already has it
     * @return array<int, int>
     */
    public static function chainUpFrom(?int $type_id, ?array $parents = null): array
    {
        $parents ??= static::parentIds();
        $chain = [];

        while ($type_id !== null && array_key_exists($type_id, $parents) && !in_array($type_id, $chain, true)) {
            $chain[] = $type_id;
            $type_id = $parents[$type_id];
        }

        return $chain;
    }

    /**
     * The types given and every type under them, however deep: the other way
     * down the same tree chainUpFrom() walks up.
     *
     * @param  array<int, int>  $ids
     * @param  ?array<int, ?int>  $parents  parentIds(), when the caller already has it
     * @return array<int, int>
     */
    public static function withDescendants(array $ids, ?array $parents = null): array
    {
        $parents ??= static::parentIds();
        $found = array_map('intval', $ids);
        $all = $found;

        while ($found !== []) {
            $found = array_keys(array_filter(
                $parents,
                fn (?int $parent_id, int $id) => in_array($parent_id, $found, true) && !in_array($id, $all, true),
                ARRAY_FILTER_USE_BOTH,
            ));
            $all = array_merge($all, $found);
        }

        return $all;
    }

    public static function flatListWithPath(): array
    {
        $result = [];
        foreach (static::pathMap() as $id => $name) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        usort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $result;
    }

    public function serviceChecks()
    {
        return $this->morphedByMany(ServiceCheck::class, 'producttypeable');
    }

    public function serviceCheckGroups()
    {
        return $this->morphedByMany(ServiceCheckGroup::class, 'producttypeable');
    }

    public function productAttributes()
    {
        return $this->morphedByMany(ProductAttribute::class, 'producttypeable');
    }
}
