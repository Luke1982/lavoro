<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\ChangeSentence;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;

/**
 * A checkpoint of a keuring was answered or its answer changed: the switch, the
 * value typed in, or the options chosen. One entry per save, named after the
 * checkpoint and the machine, on the werkbon the keuring is for, so "what did the
 * brakes score before the repair" has an answer. Not on the machine: every
 * checkpoint of every keuring would bury its own history there, and the machine
 * already hears the outcome the keuring came to (ServiceJobCompleted).
 *
 * Takes the checkpoint's result before and after the save as
 * ServiceCheckInstance::resultSnapshot() gives it.
 */
class ServiceCheckResultChanged extends ServiceJobSignal
{
    /** What each answer field of a checkpoint is called. */
    private const FIELD_LABELS = [
        'switch_state' => 'Schakelaar',
        'description' => 'Toelichting',
        'values' => 'Keuze',
    ];

    /**
     * The keuring stands in empty only for an unsaved checkpoint, as the guards over
     * the signal catalogue build it; a saved one always belongs to a keuring.
     *
     * @param  array<string, array{value: ?string, label: ?string}>  $before
     * @param  array<string, array{value: ?string, label: ?string}>  $after
     */
    public function __construct(
        public ServiceCheckInstance $check_instance,
        public array $before,
        public array $after,
    ) {
        parent::__construct($check_instance->serviceJob ?? new ServiceJob);
    }

    public static function key(): string
    {
        return 'servicejob.check_result_changed';
    }

    public static function label(): string
    {
        return 'Keurpunt gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityContext(): array
    {
        return [];
    }

    public function activityDescription(): ?string
    {
        $changes = $this->changes();

        if ($changes === []) {
            return null;
        }

        $machine = $this->service_job->asset?->historyLabel();

        return 'Keurpunt "' . ($this->check_instance->serviceCheck?->name ?? 'onbekend') . '"'
            . ($machine ? ' bij ' . $machine : '')
            . ': ' . ChangeSentence::of($changes);
    }

    public function changes(): array
    {
        $changes = [];

        foreach ($this->after as $field => $now) {
            $was = $this->before[$field] ?? ['value' => null, 'label' => null];

            if (($was['value'] ?? null) === ($now['value'] ?? null)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'old_value' => $was['value'] ?? null,
                'new_value' => $now['value'] ?? null,
                'old_label' => $was['label'] ?? null,
                'new_label' => $now['label'] ?? null,
            ];
        }

        return $changes;
    }
}
