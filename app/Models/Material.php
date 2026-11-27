<?php

namespace App\Models;

use App\Actions\Quotes\RemoveProductMaterialDefaultAction;
use App\Actions\Suppliers\SupplierLinkAction;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasIntegrations;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $divisable
 * @property bool $is_active
 * @property bool $is_service
 */
class Material extends Model
{
    use HasActivities;
    use HasIntegrations;
    use RecordsHistory;

    public const FINANCIAL_ATTRIBUTES = [
        'cost_price',
    ];

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
        'ledger_account_id' => 'Grootboekrekening',
    ];

    protected array $activity_relations = [
        'material_category_id' => ['category', 'name'],
        'material_usage_unit_id' => ['usageUnit', 'name'],
        'ledger_account_id' => ['ledgerAccount', 'label'],
    ];

    protected array $activity_ignore = [];

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
        'code',
        'vendor_code',
        'cost_price',
        'divisable',
        'is_active',
        'is_service',
        'stock',
        'min_stock',
        'max_stock',
        'ledger_account_id',
    ];

    /**
     * Hidden by default because a material travels along on every werkbon it is
     * booked on. The material page makes it visible for whoever may see it; its
     * changes still reach the trail, gated by $activity_permissions.
     */
    protected $hidden = self::FINANCIAL_ATTRIBUTES;

    protected $casts = [
        'divisable' => 'boolean',
        'is_active' => 'boolean',
        'is_service' => 'boolean',
    ];

    /**
     * The suppliers it was bought from at the moment it was deleted. Its links to
     * them are taken off before it goes, so they are read just before.
     *
     * @var array<int, Supplier>
     */
    private array $linked_before_delete = [];

    /**
     * A material that goes is taken off its suppliers and out of the standard
     * materials of every product, one by one, so each of them says so on its
     * timeline. Nothing else would: the supplier links are a morph pivot the
     * database knows nothing about, and the standard materials are rows of
     * productables, a morph the database cannot cascade either.
     */
    protected static function booted(): void
    {
        static::deleting(function (Material $material): void {
            $links = app(SupplierLinkAction::class);
            $material->linked_before_delete = $material->suppliers()->get()->all();

            foreach ($material->linked_before_delete as $supplier) {
                $links->unlink($material, $supplier);
            }

            $remove_default = app(RemoveProductMaterialDefaultAction::class);

            Productable::with(['product.brand', 'material'])
                ->where('productable_type', Material::class)
                ->where('productable_id', $material->id)
                ->get()
                ->each(fn (Productable $default) => $remove_default->execute($default));
        });
    }

    /**
     * A material that is deleted takes its own timeline with it; the suppliers it
     * was bought from say so.
     */
    public function historyTimelines(string $action): array
    {
        return $action === 'deleted' ? $this->linked_before_delete : [];
    }

    public function historyLabel(): ?string
    {
        return 'Materiaal ' . ($this->name ?? $this->id);
    }

    public function category()
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    public function usageUnit()
    {
        return $this->belongsTo(MaterialUsageUnit::class, 'material_usage_unit_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
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
