<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $divisable
 * @property bool $is_active
 * @property bool $is_service
 */
class Material extends Model
{
    use HasActivities;
    use RecordsHistory;

    protected array $activity_labels = [
        'description' => 'Omschrijving',
        'material_category_id' => 'Categorie',
        'material_usage_unit_id' => 'Eenheid',
        'vendor_code' => 'Leverancierscode',
        'divisable' => 'Deelbaar',
        'is_service' => 'Dienst',
        'name' => 'Naam',
        'price' => 'Prijs',
        'cost_price' => 'Kostprijs',
        'stock' => 'Voorraad',
        'min_stock' => 'Minimale voorraad',
        'max_stock' => 'Maximale voorraad',
        'is_active' => 'Actief',
        'code' => 'Code',
    ];

    protected array $activity_ignore = [
        'snelstart_id',
    ];

    /** Fields whose entries are gated behind a permission. */
    protected array $activity_permissions = [
        'cost_price' => 'material.see_financials',
    ];

    protected $fillable = [
        'name',
        'description',
        'material_category_id',
        'material_usage_unit_id',
        'price',
        'snelstart_id',
        'code',
        'vendor_code',
        'cost_price',
        'divisable',
        'is_active',
        'is_service',
        'stock',
        'min_stock',
        'max_stock',
    ];

    protected $casts = [
        'divisable' => 'boolean',
        'is_active' => 'boolean',
        'is_service' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    public function usageUnit()
    {
        return $this->belongsTo(MaterialUsageUnit::class, 'material_usage_unit_id');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main']);
    }

    public function suppliers()
    {
        return $this->morphToMany(Supplier::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }
}
