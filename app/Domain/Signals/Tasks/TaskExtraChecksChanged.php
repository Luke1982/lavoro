<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Checks picked on top of a taak's template were added or taken off when the taak
 * was edited. Named by their titles, so the werkbon still says which checks came
 * and went after the library they came from has changed.
 */
class TaskExtraChecksChanged extends BaseSignal
{
    /**
     * @param  array<int, string>  $before  Titles of the extra checks the taak carried before.
     * @param  array<int, string>  $after  Titles of the extra checks it carries now.
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
        return 'taskinstance.extra_checks_changed';
    }

    public static function label(): string
    {
        return 'Extra controles taak gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    /** @return array<int, string> */
    public function added(): array
    {
        return array_values(array_diff($this->after, $this->before));
    }

    /** @return array<int, string> */
    public function removed(): array
    {
        return array_values(array_diff($this->before, $this->after));
    }

    public function activityDescription(): ?string
    {
        $parts = array_filter([
            $this->added() === [] ? null : 'toegevoegd: ' . implode(', ', $this->added()),
            $this->removed() === [] ? null : 'verwijderd: ' . implode(', ', $this->removed()),
        ]);

        return $parts === []
            ? null
            : sprintf('Extra controles taak "%s" %s', $this->task_title, implode('; ', $parts));
    }

    public function changes(): array
    {
        if ($this->added() === [] && $this->removed() === []) {
            return [];
        }

        $before = $this->before === [] ? null : implode(', ', $this->before);
        $after = $this->after === [] ? null : implode(', ', $this->after);

        return [[
            'field' => 'extra_checks',
            'label' => 'Extra controles',
            'old_value' => $before,
            'new_value' => $after,
            'old_label' => $before,
            'new_label' => $after,
        ]];
    }
}
