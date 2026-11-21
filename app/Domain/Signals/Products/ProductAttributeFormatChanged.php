<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\BaseSignal;
use App\Enums\ProductAttributeValueTypes;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A kenmerk now holds numbers or text, or its unit changed. Becoming numeric
 * can fold values that were the same number written twice into one; how many
 * is part of the fact.
 *
 * The products whose value now reads differently -- "10" became "10 kW", or two
 * spellings became one -- hear of it on their own timelines, named by the
 * kenmerk, since nothing was saved on them.
 */
class ProductAttributeFormatChanged extends BaseSignal
{
    /** @param  array<int, Product>  $products */
    public function __construct(
        public ProductAttribute $attribute,
        public string $old_value_type,
        public ?string $old_unit,
        public int $merged = 0,
        public array $products = [],
    ) {
        parent::__construct();
    }

    public function activityContext(): array
    {
        return $this->products;
    }

    public static function key(): string
    {
        return 'productattribute.format_changed';
    }

    public static function label(): string
    {
        return 'Soort waarde gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['value_type', 'unit'];
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function subject(): Model
    {
        return $this->attribute;
    }

    public function activityMetadata(): ?array
    {
        return [
            'subject_label' => 'Kenmerk ' . $this->attribute->name,
            ...($this->merged > 0 ? ['merged_values' => $this->merged] : []),
        ];
    }

    public function activityDescription(): ?string
    {
        $sentence = match (true) {
            !$this->attribute->isNumeric() => 'Waarden zijn nu tekst',
            $this->old_value_type !== $this->attribute->value_type => 'Waarden zijn nu getallen'
                . ($this->attribute->unit ? ' in ' . $this->attribute->unit : ''),
            default => 'Eenheid gewijzigd van \'' . ($this->old_unit ?? '-') . '\' naar \''
                . ($this->attribute->unit ?? '-') . '\'',
        };

        return $this->merged === 0 ? $sentence : $sentence . ', ' . $this->merged
            . ($this->merged === 1 ? ' dubbele waarde' : ' dubbele waarden') . ' samengevoegd';
    }

    public function changes(): array
    {
        $changes = [];

        if ($this->old_value_type !== $this->attribute->value_type) {
            $changes[] = [
                'field' => 'value_type',
                'label' => 'Soort waarde',
                'old_value' => $this->old_value_type,
                'new_value' => $this->attribute->value_type,
                'old_label' => ProductAttributeValueTypes::labelOf($this->old_value_type),
                'new_label' => ProductAttributeValueTypes::labelOf($this->attribute->value_type),
            ];
        }

        if ($this->old_unit !== $this->attribute->unit) {
            $changes[] = [
                'field' => 'unit',
                'label' => 'Eenheid',
                'old_value' => $this->old_unit,
                'new_value' => $this->attribute->unit,
                'old_label' => $this->old_unit,
                'new_label' => $this->attribute->unit,
            ];
        }

        return $changes;
    }
}
