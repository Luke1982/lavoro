<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\BaseSignal;
use App\Models\Product;
use App\Models\Productable;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of a product's parts list: which product it is built out of, how many,
 * and whether that part is required. The list belongs to the product that has
 * it, so that is the subject; the part hears of it too, where its page lists
 * what it is a part of.
 */
abstract class ProductComponentSignal extends BaseSignal
{
    public const LABELS = [
        'product_relation_id' => 'Relatietype',
        'quantity' => 'Aantal',
        'flex_quantity' => 'Vrij aantal',
        'is_required' => 'Verplicht',
    ];

    public function __construct(public Product $product, public Product $component, public Productable $row)
    {
        parent::__construct();
    }

    public function subject(): Model
    {
        return $this->product;
    }

    public function activityContext(): array
    {
        return [$this->component];
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    protected function productName(): string
    {
        return "'" . self::nameOf($this->product) . "'";
    }

    protected function componentName(): string
    {
        return "'" . self::nameOf($this->component) . "'";
    }

    /** "2 stuks, verplicht" */
    protected function terms(): string
    {
        $terms = [
            match (true) {
                (bool) $this->row->flex_quantity => 'vrij aantal',
                (int) $this->row->quantity === 1 => '1 stuk',
                default => Quantity::human($this->row->quantity) . ' stuks',
            },
            $this->row->is_required ? 'verplicht' : 'niet verplicht',
        ];

        if ($this->row->productRelation) {
            $terms[] = $this->row->productRelation->name;
        }

        return implode(', ', $terms);
    }

    /**
     * The part as one change row: coming onto the list (before null) or going off
     * it (after null). The value is the part's id, the label what it was called
     * and on which terms.
     *
     * @return array<string, mixed>
     */
    protected function componentRow(bool $added): array
    {
        $id = (string) $this->component->id;
        $summary = self::nameOf($this->component) . ' (' . $this->terms() . ')';

        return [
            'field' => 'component',
            'label' => 'Onderdeel ' . self::nameOf($this->component),
            'old_value' => $added ? null : $id,
            'new_value' => $added ? $id : null,
            'old_label' => $added ? null : $summary,
            'new_label' => $added ? $summary : null,
        ];
    }

    protected static function nameOf(Product $product): string
    {
        return $product->display_name ?? 'product ' . $product->id;
    }
}
