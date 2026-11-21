<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Enums\ServiceJobOutcomes;
use App\Models\ServiceJob;

/**
 * A keuring was saved as done, with its outcome and date. Told on the werkbon it
 * was done for and on the machine it was done on; what the outcome means for the
 * machine's next inspection date is a listener's business, not the saver's.
 *
 * Carries the values from before the save, so a keuring saved again with another
 * outcome reads as the correction it is, and the listener can take back what the
 * earlier outcome gave.
 */
class ServiceJobCompleted extends ServiceJobSignal
{
    public function __construct(
        ServiceJob $service_job,
        public ?string $previous_outcome = null,
        public ?string $previous_completed_on = null,
        public ?int $previous_completed_by = null,
        public ?int $previous_days_temporary_approval = null,
    ) {
        parent::__construct($service_job);
    }

    public static function key(): string
    {
        return 'servicejob.completed';
    }

    public static function label(): string
    {
        return 'Keuring afgerond';
    }

    public static function coveredFields(): array
    {
        return ['outcome', 'days_temporary_approval', 'completed_on', 'completed_by'];
    }

    /** Whether the keuring was already done before this save. */
    public function wasCompletedBefore(): bool
    {
        return $this->previous_completed_on !== null;
    }

    /**
     * Whether this save is news: the keuring became done, or what it concluded changed.
     * Who saved a finished keuring again is not: its completer and date are only
     * replaced along with its outcome or days, so saving it again as it was changes
     * nothing here.
     */
    public function isNews(): bool
    {
        return !$this->wasCompletedBefore() || $this->changes() !== [];
    }

    public function activityDescription(): ?string
    {
        $machine = $this->service_job->asset?->historyLabel();
        $result = ($this->service_job->outcome ?? '-') . $this->daysOfApproval();

        $sentence = match (true) {
            !$this->wasCompletedBefore() => 'Keuring afgerond met uitkomst ' . $result,
            $this->conclusionChanged() => 'Uitkomst keuring gewijzigd naar ' . $result,
            default => 'Afronding keuring gecorrigeerd, uitkomst ' . $result,
        };

        return $sentence . ($machine ? ': ' . $machine : '');
    }

    public function changes(): array
    {
        $outcome = $this->service_job->outcome;
        $days = self::number($this->service_job->days_temporary_approval);
        $previous_days = self::number($this->previous_days_temporary_approval);

        return array_values(array_filter([
            $outcome === $this->previous_outcome ? null : [
                'field' => 'outcome',
                'label' => 'Uitkomst',
                'old_value' => $this->previous_outcome,
                'new_value' => $outcome,
                'old_label' => $this->previous_outcome,
                'new_label' => $outcome,
            ],
            !$this->daysChanged() ? null : [
                'field' => 'days_temporary_approval',
                'label' => 'Dagen tijdelijke goedkeuring',
                'old_value' => $previous_days,
                'new_value' => $days,
                'old_label' => $previous_days,
                'new_label' => $days,
            ],
            self::dayChange(
                'completed_on',
                'Afgerond op',
                $this->previous_completed_on,
                $this->service_job->getRawOriginal('completed_on'),
            ),
            self::personChange(
                'completed_by',
                'Afgerond door',
                $this->previous_completed_by,
                $this->service_job->completed_by === null ? null : (int) $this->service_job->completed_by,
            ),
        ]));
    }

    private static function number(mixed $value): ?string
    {
        return $value === null ? null : (string) (int) $value;
    }

    /** Whether what the keuring concluded changed: its outcome, or the days it was approved for. */
    private function conclusionChanged(): bool
    {
        return $this->service_job->outcome !== $this->previous_outcome || $this->daysChanged();
    }

    /** No days and 0 days are the same: the form sends 0 for an outcome that has none. */
    private function daysChanged(): bool
    {
        return (int) $this->service_job->days_temporary_approval !== (int) $this->previous_days_temporary_approval;
    }

    private function daysOfApproval(): string
    {
        return $this->service_job->outcome === ServiceJobOutcomes::tijdelijk_goedkeur->value
            ? ' voor ' . (int) $this->service_job->days_temporary_approval . ' dagen'
            : '';
    }
}
