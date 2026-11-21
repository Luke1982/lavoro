<?php

namespace App\Domain\Signals\Suppliers;

use App\Domain\Signals\ChangeSentence;
use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * The article number a product or material has at a supplier changed, or that
 * supplier became or stopped being the one to order from first.
 */
class SupplierLinkChanged extends SupplierLinkSignal
{
    private const LABELS = [
        'article_number' => 'Artikelnummer',
        'is_preferred' => 'Voorkeursleverancier',
    ];

    /**
     * @param  Product|Material  $item
     * @param  array<string, array{0: mixed, 1: mixed}>  $fields  field => [before, after]
     */
    public function __construct(Model $item, Supplier $supplier, public array $fields)
    {
        parent::__construct($item, $supplier);
    }

    public static function key(): string
    {
        return 'supplier_link.changed';
    }

    public static function label(): string
    {
        return 'Leverancier bijgewerkt';
    }

    public function activityDescription(): ?string
    {
        return 'Leverancier ' . $this->supplierName() . ' bij ' . $this->itemName() . ': '
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
                'label' => self::LABELS[$field] . ' bij ' . $this->supplier->name,
                'old_value' => self::raw($before),
                'new_value' => self::raw($after),
                'old_label' => self::readable($before),
                'new_label' => self::readable($after),
            ];
        }

        return $changes;
    }

    private static function raw(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private static function readable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Ja' : 'Nee',
            default => (string) $value,
        };
    }
}
