<?php

namespace App\Models;

use App\Traits\HasParent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    /** @use HasFactory<\Database\Factories\ProductTypeFactory> */
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
        $all   = static::orderBy('name')->get()->keyBy('id');
        $paths = [];
        foreach ($all as $type) {
            $path    = [$type->name];
            $current = $type;
            while ($current->parent_id && isset($all[$current->parent_id])) {
                $current = $all[$current->parent_id];
                array_unshift($path, $current->name);
            }
            $paths[$type->id] = implode(' → ', $path);
        }
        return $paths;
    }

    public static function flatListWithPath(): array
    {
        $result = [];
        foreach (static::pathMap() as $id => $name) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));

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
}
