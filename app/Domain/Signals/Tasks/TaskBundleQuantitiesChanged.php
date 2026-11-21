<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\ChangeSentence;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * The aantallen filled in for a bundle's open parts changed on a taak: how many
 * panelen this roof takes. Those rows are rewritten as a whole on every save, so
 * this compares the parts before and after and only tells what really moved.
 */
class TaskBundleQuantitiesChanged extends BaseSignal
{
    /**
     * @param  array<int, array{name: string, quantity: int}>  $before  Per product id.
     * @param  array<int, array{name: string, quantity: int}>  $after  Per product id.
     */
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public array $before,
        public array $after,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.bundle_quantities_changed';
    }

    public static function label(): string
    {
        return 'Aantallen bundel taak gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        $changes = $this->changes();

        return $changes === []
            ? null
            : sprintf('Aantallen taak "%s": %s', $this->task_title, ChangeSentence::of($changes));
    }

    public function changes(): array
    {
        $changes = [];

        foreach (array_unique(array_merge(array_keys($this->before), array_keys($this->after))) as $product_id) {
            $was = $this->before[$product_id]['quantity'] ?? null;
            $now = $this->after[$product_id]['quantity'] ?? null;

            if ($was === $now) {
                continue;
            }

            $changes[] = [
                'field' => 'flex_part.' . $product_id,
                'label' => $this->after[$product_id]['name'] ?? $this->before[$product_id]['name'] ?? 'Onderdeel',
                'old_value' => $was === null ? null : (string) $was,
                'new_value' => $now === null ? null : (string) $now,
                'old_label' => $was === null ? null : (string) $was,
                'new_label' => $now === null ? null : (string) $now,
            ];
        }

        return $changes;
    }
}
