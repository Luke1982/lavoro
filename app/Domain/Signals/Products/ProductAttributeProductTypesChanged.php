<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\ChangeSentence;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Model;

/**
 * The product types a kenmerk applies to changed, and with them the products
 * whose form asks for it. Before and after are the types by id and by path.
 */
class ProductAttributeProductTypesChanged extends BaseSignal
{
    /**
     * @param  array<int, string>  $old_types  id → path
     * @param  array<int, string>  $new_types  id → path
     */
    public function __construct(public ProductAttribute $attribute, public array $old_types, public array $new_types)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'productattribute.product_types_changed';
    }

    public static function label(): string
    {
        return 'Producttypen van kenmerk gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function subject(): Model
    {
        return $this->attribute;
    }

    public function activityDescription(): ?string
    {
        return ChangeSentence::of($this->changes());
    }

    public function changes(): array
    {
        return [[
            'field' => 'product_types',
            'label' => 'Producttypen',
            'old_value' => self::ids($this->old_types),
            'new_value' => self::ids($this->new_types),
            'old_label' => self::names($this->old_types),
            'new_label' => self::names($this->new_types),
        ]];
    }

    private static function ids(array $types): ?string
    {
        return $types === [] ? null : implode(',', array_keys($types));
    }

    private static function names(array $types): ?string
    {
        return $types === [] ? null : implode(', ', $types);
    }
}
