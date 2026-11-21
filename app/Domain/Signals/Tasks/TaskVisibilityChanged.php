<?php

namespace App\Domain\Signals\Tasks;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * Who gets to see a werkbontaak changed. A taak without roles is everyone's, one
 * with roles only theirs who carry out the werkbon in one of them, so this decides
 * whether a monteur finds the taak at all. The roles are kept by name.
 */
class TaskVisibilityChanged extends BaseSignal
{
    /**
     * @param  array<int, string>  $previous_roles  Role names by id, before.
     * @param  array<int, string>  $new_roles  Role names by id, after.
     */
    public function __construct(
        public ServiceOrder $service_order,
        public ServiceOrderTaskInstance $task_instance,
        public string $task_title,
        public array $previous_roles,
        public array $new_roles,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'taskinstance.visibility_changed';
    }

    public static function label(): string
    {
        return 'Zichtbaarheid taak gewijzigd';
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
        if ($this->new_roles === []) {
            return sprintf('Taak "%s" is weer voor iedereen zichtbaar', $this->task_title);
        }

        return sprintf(
            'Taak "%s" alleen zichtbaar voor: %s',
            $this->task_title,
            NameListChange::names(array_values($this->new_roles)),
        );
    }

    /** A taak without roles is everyone's, so an empty list reads as "Iedereen". */
    public function changes(): array
    {
        $roles = new NameListChange($this->previous_roles, $this->new_roles);
        $change = $roles->change('user_role_ids', 'Zichtbaar voor');
        $change['old_label'] ??= 'Iedereen';
        $change['new_label'] ??= 'Iedereen';

        return [$change];
    }
}
