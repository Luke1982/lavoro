<?php

namespace App\Domain\Signals\Pricing;

use App\Domain\Signals\BaseSignal;
use App\Models\PriceFormula;
use Illuminate\Database\Eloquent\Model;

/** A version of a price formula got another name, such as the year its prices are for. */
class PriceFormulaVersionRenamed extends BaseSignal
{
    public function __construct(
        public PriceFormula $formula,
        public int $version,
        public ?string $previous_name,
        public ?string $name,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'priceformula.version_renamed';
    }

    public static function label(): string
    {
        return 'Versie van prijsformule hernoemd';
    }

    public function activityCategory(): string
    {
        return 'updated';
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
        return $this->name === null
            ? 'Naam van versie ' . $this->version . ' weggehaald'
            : 'Versie ' . $this->version . ' heet nu "' . $this->name . '"';
    }

    public function changes(): array
    {
        return [[
            'field' => 'version_name',
            'label' => 'Naam van versie ' . $this->version,
            'old_value' => $this->previous_name,
            'new_value' => $this->name,
            'old_label' => $this->previous_name,
            'new_label' => $this->name,
        ]];
    }
}
