<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

/**
 * How often one machine on a contract is serviced changed. No frequency of its
 * own means it follows the contract's. Told on the contract and on the machine.
 */
class ContractAssetFrequencyChanged extends BaseSignal
{
    use NamesTheContract;

    public function __construct(
        public MaintenanceContract $contract,
        public Asset $asset,
        public ?string $previous_frequency,
        public ?int $previous_frequency_days,
        public ?string $frequency,
        public ?int $frequency_days,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.asset_frequency_changed';
    }

    public static function label(): string
    {
        return 'Machinefrequentie gewijzigd';
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
        return 'Machinefrequentie bijgewerkt: ' . ContractLabels::asset($this->asset)
            . ' van ' . $this->frequencyLabel($this->previous_frequency, $this->previous_frequency_days)
            . ' naar ' . $this->frequencyLabel($this->frequency, $this->frequency_days);
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

    public function changes(): array
    {
        $changes = [];

        if ($this->previous_frequency !== $this->frequency) {
            $changes[] = [
                'field' => 'frequency',
                'label' => 'Frequentie',
                'old_value' => $this->previous_frequency,
                'new_value' => $this->frequency,
                'old_label' => $this->previous_frequency ?? 'contractfrequentie',
                'new_label' => $this->frequency ?? 'contractfrequentie',
            ];
        }

        if ($this->previous_frequency_days !== $this->frequency_days) {
            $changes[] = [
                'field' => 'frequency_days',
                'label' => 'Aantal dagen',
                'old_value' => $this->previous_frequency_days === null ? null : (string) $this->previous_frequency_days,
                'new_value' => $this->frequency_days === null ? null : (string) $this->frequency_days,
                'old_label' => $this->daysLabel($this->previous_frequency_days),
                'new_label' => $this->daysLabel($this->frequency_days),
            ];
        }

        return $changes;
    }

    private function daysLabel(?int $days): ?string
    {
        return $days === null ? null : $days . ' dagen';
    }

    private function frequencyLabel(?string $frequency, ?int $days): string
    {
        return $frequency === null ? 'contractfrequentie' : ContractLabels::frequency($frequency, $days);
    }
}
