<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\PersonName;
use App\Models\ServiceJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something happened to a keuring. A keuring has no screen of its own, so the entry
 * is about the werkbon it was done for and is told on the machine it was done on
 * too, where it names that werkbon.
 */
abstract class ServiceJobSignal extends BaseSignal
{
    public function __construct(public ServiceJob $service_job)
    {
        parent::__construct();
    }

    /**
     * The werkbon, which every keuring has. The keuring itself only stands in for an
     * unsaved one, as the guards over the signal catalogue build it.
     */
    public function subject(): Model
    {
        return $this->service_job->serviceOrder ?? $this->service_job;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->service_job->asset]));
    }

    /**
     * The keuring by name, and the werkbon, which the machine's page links to.
     *
     * @return array<string, int|string>|null
     */
    public function activityMetadata(): ?array
    {
        $service_order = $this->service_job->serviceOrder;

        $metadata = array_filter([
            'subject_label' => $this->service_job->historyLabel(),
            'service_order_id' => $service_order?->getKey(),
            'service_order_number' => $service_order?->number,
        ], fn ($value) => $value !== null);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * The change row of a day the keuring stores, or null when it is the same day. The
     * column hands back 2026-01-01 where a fresh save holds 2026-01-01 00:00:00; both
     * are kept as the day and read as 01-01-2026, the way the generic trail reads a date.
     *
     * @return array<string, ?string>|null
     */
    protected static function dayChange(string $field, string $label, mixed $before, mixed $after): ?array
    {
        $old = self::day($before);
        $new = self::day($after);

        if ($old?->toDateString() === $new?->toDateString()) {
            return null;
        }

        return [
            'field' => $field,
            'label' => $label,
            'old_value' => $old?->toDateString(),
            'new_value' => $new?->toDateString(),
            'old_label' => $old?->format('d-m-Y'),
            'new_label' => $new?->format('d-m-Y'),
        ];
    }

    /**
     * The change row of who did it, or null when it is the same person. Named the way
     * the trail names a person: past every scope, and MajorLabel for its own accounts.
     *
     * @return array<string, ?string>|null
     */
    protected static function personChange(string $field, string $label, ?int $before, ?int $after): ?array
    {
        if ($before === $after) {
            return null;
        }

        return [
            'field' => $field,
            'label' => $label,
            'old_value' => $before === null ? null : (string) $before,
            'new_value' => $after === null ? null : (string) $after,
            'old_label' => PersonName::of($before),
            'new_label' => PersonName::of($after),
        ];
    }

    private static function day(mixed $stored): ?Carbon
    {
        return $stored === null || $stored === '' ? null : Carbon::parse($stored);
    }
}
