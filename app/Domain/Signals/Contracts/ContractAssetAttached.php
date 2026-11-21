<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

/**
 * A machine came onto a contract. Told on the contract and on the machine, not
 * on the customer: which machines a contract covers is the contract's business.
 * A machine on a contract with a frequency per machine comes with its own; no
 * frequency means it follows the contract's.
 */
class ContractAssetAttached extends BaseSignal
{
    use NamesTheContract;

    public function __construct(
        public MaintenanceContract $contract,
        public Asset $asset,
        public ?string $frequency = null,
        public ?int $frequency_days = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.asset_attached';
    }

    public static function label(): string
    {
        return 'Machine gekoppeld aan contract';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        $frequency = $this->frequency
            ? ' (frequentie: ' . ContractLabels::frequency($this->frequency, $this->frequency_days) . ')'
            : '';

        return 'Machine gekoppeld: ' . ContractLabels::asset($this->asset) . $frequency;
    }

    /** On the machine's own timeline, under the contract's name. */
    public function activityContext(): array
    {
        return [$this->asset];
    }

    public function activityMetadata(): ?array
    {
        return $this->withContractLabel();
    }

    /** The machine's own frequency, read the way ContractAssetFrequencyChanged reads a change of it. */
    public function changes(): array
    {
        if ($this->frequency === null) {
            return [];
        }

        $changes = [[
            'field' => 'frequency',
            'label' => 'Frequentie',
            'old_value' => null,
            'new_value' => $this->frequency,
            'old_label' => null,
            'new_label' => $this->frequency,
        ]];

        if ($this->frequency_days !== null) {
            $changes[] = [
                'field' => 'frequency_days',
                'label' => 'Aantal dagen',
                'old_value' => null,
                'new_value' => (string) $this->frequency_days,
                'old_label' => null,
                'new_label' => $this->frequency_days . ' dagen',
            ];
        }

        return $changes;
    }
}
