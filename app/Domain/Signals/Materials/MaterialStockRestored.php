<?php

namespace App\Domain\Signals\Materials;

use App\Domain\Signals\BaseSignal;
use App\Models\Material;
use Illuminate\Database\Eloquent\Model;

class MaterialStockRestored extends BaseSignal
{
    use ReportsStock;

    public function __construct(
        public Material $material,
        public float $quantity,
        public string $reason,
        public ?float $stock_before = null,
        public ?float $stock_after = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'material.stock_restored';
    }

    public static function label(): string
    {
        return 'Voorraad hersteld';
    }

    public function activityCategory(): string
    {
        return 'material';
    }

    public function subject(): Model
    {
        return $this->material;
    }

    public function activityDescription(): ?string
    {
        return 'Voorraad hersteld: +' . $this->quantity . ' door ' . $this->reason;
    }

    public function changes(): array
    {
        return self::stockChanges($this->stock_before, $this->stock_after);
    }
}
