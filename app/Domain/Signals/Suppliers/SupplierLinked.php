<?php

namespace App\Domain\Signals\Suppliers;

use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierLinked extends SupplierLinkSignal
{
    /**
     * @param  Product|Material  $item
     * @param  array{article_number: ?string, is_preferred: bool}  $link
     */
    public function __construct(Model $item, Supplier $supplier, public array $link)
    {
        parent::__construct($item, $supplier);
    }

    public static function key(): string
    {
        return 'supplier_link.added';
    }

    public static function label(): string
    {
        return 'Leverancier gekoppeld';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Leverancier ' . $this->supplierName() . ' gekoppeld aan ' . $this->itemName()
            . ' (' . self::terms($this->link) . ')';
    }

    public function changes(): array
    {
        return [$this->linkRow($this->link, true)];
    }
}
