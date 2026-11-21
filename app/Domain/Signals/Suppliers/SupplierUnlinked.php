<?php

namespace App\Domain\Signals\Suppliers;

use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierUnlinked extends SupplierLinkSignal
{
    /**
     * @param  Product|Material  $item
     * @param  array{article_number: ?string, is_preferred: bool}  $link  as it was
     */
    public function __construct(Model $item, Supplier $supplier, public array $link)
    {
        parent::__construct($item, $supplier);
    }

    public static function key(): string
    {
        return 'supplier_link.removed';
    }

    public static function label(): string
    {
        return 'Leverancier ontkoppeld';
    }

    public function activityDescription(): ?string
    {
        return 'Leverancier ' . $this->supplierName() . ' ontkoppeld van ' . $this->itemName()
            . ' (was ' . self::terms($this->link) . ')';
    }

    public function changes(): array
    {
        return [$this->linkRow($this->link, false)];
    }
}
