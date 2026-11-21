<?php

namespace App\Domain\Signals\Suppliers;

use App\Domain\Signals\BaseSignal;
use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a product or material is bought: which supplier, under which article
 * number there, and whether it is the one to order from first. The fact is
 * about the product or material, whose page lists its suppliers; the supplier's
 * own timeline has it as well.
 */
abstract class SupplierLinkSignal extends BaseSignal
{
    /** @param  Product|Material  $item */
    public function __construct(public Model $item, public Supplier $supplier)
    {
        parent::__construct();
    }

    public function subject(): Model
    {
        return $this->item;
    }

    public function activityContext(): array
    {
        return [$this->supplier];
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    protected function itemName(): string
    {
        $name = $this->item instanceof Product ? $this->item->display_name : $this->item->getAttribute('name');

        return "'" . ($name ?? $this->item->getKey()) . "'";
    }

    protected function supplierName(): string
    {
        return "'" . $this->supplier->name . "'";
    }

    /**
     * "artikelnummer AB-12, voorkeursleverancier"
     *
     * @param  array{article_number: ?string, is_preferred: bool}  $link
     */
    protected static function terms(array $link): string
    {
        return implode(', ', array_filter([
            filled($link['article_number'] ?? null) ? 'artikelnummer ' . $link['article_number'] : 'zonder artikelnummer',
            !empty($link['is_preferred']) ? 'voorkeursleverancier' : null,
        ]));
    }

    /**
     * The link as one change row: coming on (before null) or going off (after
     * null). The value is the supplier's id, the label its name and the terms.
     *
     * @param  array{article_number: ?string, is_preferred: bool}  $link
     * @return array<string, mixed>
     */
    protected function linkRow(array $link, bool $added): array
    {
        $id = (string) $this->supplier->id;
        $summary = $this->supplier->name . ' (' . self::terms($link) . ')';

        return [
            'field' => 'supplier',
            'label' => 'Leverancier ' . $this->supplier->name,
            'old_value' => $added ? null : $id,
            'new_value' => $added ? $id : null,
            'old_label' => $added ? null : $summary,
            'new_label' => $added ? $summary : null,
        ];
    }
}
