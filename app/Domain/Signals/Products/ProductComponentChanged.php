<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\ChangeSentence;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ProductRelation;
use App\Support\Quantity;

/**
 * How a part is on a product's parts list changed: how many, whether the number
 * is settled only when the bundle is sold, whether it is required, or as what.
 */
class ProductComponentChanged extends ProductComponentSignal
{
    /** @param  array<string, array{0: mixed, 1: mixed}>  $fields  field => [before, after] */
    public function __construct(Product $product, Product $component, Productable $row, public array $fields)
    {
        parent::__construct($product, $component, $row);
    }

    public static function key(): string
    {
        return 'product.component_changed';
    }

    public static function label(): string
    {
        return 'Onderdeel gewijzigd';
    }

    public function activityDescription(): ?string
    {
        return 'Onderdeel ' . $this->componentName() . ' van ' . $this->productName() . ': '
            . ChangeSentence::of(array_map(
                fn (array $change) => ['label' => self::LABELS[$change['field']]] + $change,
                $this->changes(),
            ));
    }

    public function changes(): array
    {
        $changes = [];

        foreach ($this->fields as $field => [$before, $after]) {
            $changes[] = [
                'field' => $field,
                'label' => self::LABELS[$field] . ' (' . self::nameOf($this->component) . ')',
                'old_value' => self::normalised($field, $before),
                'new_value' => self::normalised($field, $after),
                'old_label' => self::readable($field, $before),
                'new_label' => self::readable($field, $after),
            ];
        }

        return $changes;
    }

    private static function normalised(string $field, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            in_array($field, ['flex_quantity', 'is_required'], true) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private static function readable(string $field, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            in_array($field, ['flex_quantity', 'is_required'], true) => $value ? 'Ja' : 'Nee',
            $field === 'quantity' => Quantity::human($value),
            $field === 'product_relation_id' => ProductRelation::find($value)?->name ?? (string) $value,
            default => (string) $value,
        };
    }
}
