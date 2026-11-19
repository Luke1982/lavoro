<?php

namespace App\Domain\Signals\Projects;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;

/**
 * One or more fields of a milestone were edited by hand. Reaching it or opening it
 * again are facts of their own, because they are what people ask about; moving
 * the date it was reached from one day to another is a correction, and lands here.
 */
class MilestoneChanged extends MilestoneSignal
{
    private const LABELS = [
        'title' => 'titel',
        'description' => 'omschrijving',
        'projected_date' => 'geplande datum',
        'actual_date' => 'behaald op',
        'assigned_user_id' => 'verantwoordelijke',
    ];

    /**
     * @param  array<string, mixed>  $previous  the fields as they were, by name
     */
    public function __construct(
        ProjectMilestone $milestone,
        Project $project,
        public array $previous,
    ) {
        parent::__construct($milestone, $project);
    }

    public static function key(): string
    {
        return 'project.milestone_changed';
    }

    public static function label(): string
    {
        return 'Mijlpaal gewijzigd';
    }

    /** @return array<int, string> */
    public static function fields(): array
    {
        return array_keys(self::LABELS);
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        return $this->named() . ' gewijzigd: ' . implode(', ', array_map(
            fn (array $change) => $change['label'] . " van '" . ($change['old_label'] ?? '-')
                . "' naar '" . ($change['new_label'] ?? '-') . "'",
            $this->changes(),
        ));
    }

    public function mergeKey(): ?string
    {
        return 'milestone_changed|' . $this->milestone->getKey();
    }

    public function changes(): array
    {
        $changes = [];

        foreach ($this->previous as $field => $old_value) {
            $new_value = $this->milestone->getAttribute($field);

            $changes[] = [
                'field' => $field,
                'label' => $this->named() . ': ' . self::LABELS[$field],
                'old_value' => $old_value === null ? null : (string) $old_value,
                'new_value' => $new_value === null ? null : (string) $new_value,
                'old_label' => $this->readable($field, $old_value),
                'new_label' => $this->readable($field, $new_value),
            ];
        }

        return $changes;
    }

    private function readable(string $field, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return match ($field) {
            'projected_date', 'actual_date' => self::readableDate((string) $value),
            'assigned_user_id' => User::withTrashed()->find($value)?->name,
            default => (string) $value,
        };
    }
}
