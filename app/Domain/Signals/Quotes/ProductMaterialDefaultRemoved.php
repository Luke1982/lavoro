<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * A material no longer comes along with a product on quotes. Quotes that already
 * hold it keep it: they are what was offered.
 */
class ProductMaterialDefaultRemoved extends BaseSignal
{
    public function __construct(public Product $product, public string $material_name)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'product.material_default_removed';
    }

    public static function label(): string
    {
        return 'Standaardmateriaal verwijderd';
    }

    public function subject(): Model
    {
        return $this->product;
    }

    public function activityCategory(): string
    {
        return 'deleted';
    }

    public function activityDescription(): ?string
    {
        return 'Standaardmateriaal ' . $this->material_name . ' verwijderd bij '
            . ($this->product->display_name ?? 'product ' . $this->product->id);
    }
}
