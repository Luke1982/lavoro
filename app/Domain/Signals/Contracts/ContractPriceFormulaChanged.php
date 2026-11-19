<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A contract was put on a price formula, moved to another version of it, taken
 * off it, or had what is filled in for the formula's inputs changed. What that
 * does to the price is a fact of its own, ContractPriceRecalculated, so a change
 * that happens to leave the price as it was still shows up.
 */
class ContractPriceFormulaChanged extends BaseSignal
{
    /**
     * @param  array<int, array{name: string, old: mixed, new: mixed, default: float}>  $input_changes
     */
    public function __construct(
        public MaintenanceContract $contract,
        public ?string $previous_version,
        public ?string $new_version,
        public array $input_changes = [],
        public ?float $dropped_one_time_price = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.price_formula_changed';
    }

    public static function label(): string
    {
        return 'Prijsformule gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['price_formula_version_id', 'price_formula_inputs', 'one_time_price'];
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function requiredPermission(): ?string
    {
        return 'maintenancecontract.see_financials';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        if ($this->previous_version === $this->new_version) {
            return 'Invoer voor de prijsformule gewijzigd';
        }

        if ($this->previous_version === null) {
            return 'Prijsformule gekoppeld: ' . $this->new_version;
        }

        if ($this->new_version === null) {
            return 'Prijsformule losgekoppeld (was ' . $this->previous_version . '); '
                . 'de prijs wordt voortaan met de hand ingevuld'
                . ($this->dropped_one_time_price === null
                    ? ''
                    : ', de eenmalige kosten van ' . Money::euros($this->dropped_one_time_price) . ' vervallen');
        }

        return 'Prijsformule gewijzigd van ' . $this->previous_version . ' naar ' . $this->new_version;
    }

    public function changes(): array
    {
        $changes = [];

        if ($this->previous_version !== $this->new_version) {
            $changes[] = [
                'field' => 'price_formula_version_id',
                'label' => 'Prijsformule',
                'old_value' => $this->previous_version,
                'new_value' => $this->new_version,
                'old_label' => $this->previous_version,
                'new_label' => $this->new_version,
            ];
        }

        if ($this->dropped_one_time_price !== null) {
            $changes[] = [
                'field' => 'one_time_price',
                'label' => 'Eenmalige kosten',
                'old_value' => number_format($this->dropped_one_time_price, 2, '.', ''),
                'new_value' => null,
                'old_label' => Money::euros($this->dropped_one_time_price),
                'new_label' => null,
            ];
        }

        foreach ($this->input_changes as $change) {
            $old = $this->shown($change['old'], $change['default']);
            $new = $this->shown($change['new'], $change['default']);
            $changes[] = [
                'field' => 'price_formula_inputs',
                'label' => $change['name'],
                'old_value' => $old,
                'new_value' => $new,
                'old_label' => $old,
                'new_label' => $new,
            ];
        }

        return $changes;
    }

    private function shown(mixed $value, float $default): string
    {
        $number = $value === null ? $default : (float) $value;
        $text = rtrim(rtrim(number_format($number, 2, ',', '.'), '0'), ',');

        return $value === null ? $text . ' (standaard)' : $text;
    }
}
