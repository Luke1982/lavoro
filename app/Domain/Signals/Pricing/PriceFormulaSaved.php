<?php

namespace App\Domain\Signals\Pricing;

use App\Domain\Signals\BaseSignal;
use App\Models\PriceFormula;
use Illuminate\Database\Eloquent\Model;

/**
 * The blocks of a price formula changed: a new version, or the version no
 * contract uses yet adjusted. Before and after are the blocks in words, so the
 * timeline and the assistant can say what a price per piece used to be.
 */
class PriceFormulaSaved extends BaseSignal
{
    public function __construct(
        public PriceFormula $formula,
        public int $version,
        public bool $is_new_version,
        public ?string $previous_blocks,
        public string $new_blocks,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'priceformula.saved';
    }

    public static function label(): string
    {
        return 'Prijsformule opgeslagen';
    }

    public function activityCategory(): string
    {
        return 'update';
    }

    public function requiredPermission(): ?string
    {
        return 'priceformula.read';
    }

    public function subject(): Model
    {
        return $this->formula;
    }

    public function activityDescription(): ?string
    {
        return $this->is_new_version
            ? 'Opgeslagen als versie ' . $this->version
            : 'Versie ' . $this->version . ' aangepast';
    }

    public function changes(): array
    {
        return [[
            'field' => 'graph',
            'label' => 'Blokken',
            'old_value' => $this->previous_blocks,
            'new_value' => $this->new_blocks,
            'old_label' => $this->previous_blocks,
            'new_label' => $this->new_blocks,
        ]];
    }
}
