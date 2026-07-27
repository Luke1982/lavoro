<?php

namespace App\Domain\Signals\Materials;

use App\Domain\Signals\BaseSignal;
use App\Models\Material;
use Illuminate\Database\Eloquent\Model;

class MaterialStockRestored extends BaseSignal
{
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
        return [[
            'field' => 'stock',
            'label' => 'Voorraad',
            'old_value' => $this->stock_before === null ? null : (string) $this->stock_before,
            'new_value' => $this->stock_after === null ? null : (string) $this->stock_after,
            'old_label' => $this->stock_before === null ? null : (string) $this->stock_before,
            'new_label' => $this->stock_after === null ? null : (string) $this->stock_after,
        ]];
    }
}
