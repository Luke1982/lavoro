<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A contract with a price formula got a new price because something it counts
 * changed: a machine came or went, moved to another address, or the formula
 * itself was changed. Machines attached in one go are one entry, from the price
 * before the first to the price after the last.
 */
class ContractPriceRecalculated extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public ?int $previous_price_cents,
        public ?int $new_price_cents,
        public ?int $previous_one_time_cents,
        public ?int $new_one_time_cents,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.price_recalculated';
    }

    public static function label(): string
    {
        return 'Contractprijs herberekend';
    }

    /** Only ever written by the recalculation, so the automatic trail leaves it alone. */
    public static function coveredFields(): array
    {
        return ['one_time_price'];
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

    public function mergeKey(): ?string
    {
        return 'price_recalculated';
    }

    public function activityDescription(): ?string
    {
        $parts = array_map(
            fn (array $change) => $change['label'] . ' van ' . ($change['old_label'] ?? '-')
                . ' naar ' . ($change['new_label'] ?? '-'),
            $this->changes()
        );

        return 'Prijs opnieuw berekend met de prijsformule: ' . implode(', ', $parts);
    }

    public function changes(): array
    {
        $changes = [];

        if ($this->previous_price_cents !== $this->new_price_cents) {
            $changes[] = $this->change('price', 'Prijs', $this->previous_price_cents, $this->new_price_cents);
        }

        if ($this->previous_one_time_cents !== $this->new_one_time_cents) {
            $changes[] = $this->change(
                'one_time_price',
                'Eenmalige kosten',
                $this->previous_one_time_cents,
                $this->new_one_time_cents
            );
        }

        return $changes;
    }

    private function change(string $field, string $label, ?int $old, ?int $new): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'old_value' => $old === null ? null : number_format($old / 100, 2, '.', ''),
            'new_value' => $new === null ? null : number_format($new / 100, 2, '.', ''),
            'old_label' => $old === null ? null : Money::euros($old / 100),
            'new_label' => $new === null ? null : Money::euros($new / 100),
        ];
    }
}
