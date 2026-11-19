<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\ChangeSentence;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Kenmerken of one product were set, changed or cleared: on the product page,
 * by a bulk edit, on creation or by the assistant. One entry per kenmerk, with
 * the value id raw and the value as it read, before and after.
 */
class ProductAttributesChanged extends BaseSignal
{
    /** @param  array<int, array<string, ?string>>  $changes  change rows, as RecordsHistory writes them */
    public function __construct(public Product $product, public array $changes)
    {
        parent::__construct();
    }

    /** The field a kenmerk's value is reported under, on the product and on the kenmerk alike. */
    public static function field(int $attribute_id): string
    {
        return 'product_attribute_' . $attribute_id;
    }

    public static function key(): string
    {
        return 'product.attributes_changed';
    }

    public static function label(): string
    {
        return 'Kenmerken gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function subject(): Model
    {
        return $this->product;
    }

    public function activityDescription(): ?string
    {
        return ChangeSentence::of($this->changes);
    }

    public function changes(): array
    {
        return $this->changes;
    }

    public function mergeKey(): ?string
    {
        return 'attributes';
    }
}
