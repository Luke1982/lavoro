<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Signals\BaseSignal;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone finished a project while werkbonnen under it were still open. That is
 * allowed, and the person was warned; this keeps which ones they were, because
 * "why is there still an open werkbon on a finished project" is asked later.
 */
class ProjectClosedWithOpenOrders extends BaseSignal implements ProjectSignal
{
    /**
     * @param  array<int, string>  $open_order_numbers
     */
    public function __construct(
        public Project $project,
        public array $open_order_numbers,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'project.closed_with_open_orders';
    }

    public static function label(): string
    {
        return 'Project afgerond met open werkbonnen';
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function subject(): Model
    {
        return $this->project;
    }

    public function activityCategory(): string
    {
        return 'warning';
    }

    public function activityDescription(): ?string
    {
        return 'Project afgerond terwijl ' . $this->summary() . ' nog open ' . $this->verb() . ': '
            . implode(', ', $this->open_order_numbers);
    }

    public function activityMetadata(): ?array
    {
        return ['open_order_numbers' => $this->open_order_numbers];
    }

    /** The warning the person reads when they finish the project, worded once. */
    public function warning(): string
    {
        return 'Project afgerond, maar ' . $this->summary() . ' ' . $this->verb() . ' nog open: '
            . implode(', ', $this->open_order_numbers) . '.';
    }

    private function summary(): string
    {
        $count = count($this->open_order_numbers);

        return $count === 1 ? '1 werkbon' : $count . ' werkbonnen';
    }

    private function verb(): string
    {
        return count($this->open_order_numbers) === 1 ? 'staat' : 'staan';
    }
}
