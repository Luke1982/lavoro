<?php

namespace App\Listeners;

use App\Domain\Signals\ActivityBuffer;
use App\Domain\Signals\Signal;
use App\Models\Activity;
use App\Models\ActivityChange;
use Illuminate\Support\Facades\Log;

/**
 * Writes the log entry for any signal. Registered by Laravel's listener
 * discovery through the Signal type hint, so every present and future signal is
 * covered without touching this class or registering anything by hand.
 *
 * The activity is written directly rather than through the subject model: by the
 * time this runs the transaction has committed and a deleted record no longer
 * exists, so only its type and id are safe to keep.
 */
class RecordActivity
{
    public function __construct(private ActivityBuffer $buffer) {}

    /**
     * A broken audit trail must never break the work it describes. Anything that
     * goes wrong here is reported to the log and swallowed, so a user's save
     * still succeeds. Business rules that react to signals deliberately do not
     * do this: if one of those fails, the whole operation should fail with it.
     */
    public function handle(Signal $signal): void
    {
        try {
            $this->record($signal);
        } catch (\Throwable $e) {
            Log::error('Kon activiteit niet vastleggen', [
                'signal' => $signal::key(),
                'event_key' => $signal->eventKey(),
                'exception' => $e,
            ]);
        }
    }

    private function record(Signal $signal): void
    {
        $subject = $signal->subject();
        $description = $signal->activityDescription();
        $changes = $signal->changes();

        if ($description === null && $changes === []) {
            return;
        }

        $subject_type = $subject->getMorphClass();
        $subject_id = $subject->getKey();
        $merge_key = $signal->mergeKey();

        $existing = $merge_key === null
            ? null
            : $this->buffer->existingFor($subject_type, $subject_id, $merge_key, $signal->actorType());

        if ($existing) {
            $this->mergeInto($existing, $changes);

            return;
        }

        $activity = Activity::create([
            'description' => $description ?? '',
            'category' => $signal->activityCategory(),
            'event_key' => $signal->eventKey(),
            'subject_type' => $subject_type,
            'subject_id' => $subject_id,
            'user_id' => $signal->actorId(),
            'actor_type' => $signal->actorType(),
            'actor_name' => $signal->actorName(),
            'metadata' => $signal->activityMetadata(),
            'occurred_at' => $signal->occurredAt(),
            'correlation_id' => $signal->correlationId(),
        ]);

        foreach ($changes as $change) {
            $this->writeChange($activity->id, $change);
        }

        if ($merge_key !== null) {
            $this->buffer->remember($subject_type, $subject_id, $merge_key, $activity->id, $signal->actorType());
        }

        $this->attachToTimelines($activity, $signal);
    }

    /**
     * Folds a later save of the same record into the entry already written for
     * this request. A field that moves twice keeps its original starting value,
     * so the entry reads as the whole action rather than as its last step.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function mergeInto(Activity $activity, array $changes): void
    {
        foreach ($changes as $change) {
            $existing_change = $activity->fieldChanges
                ->firstWhere('field', $change['field']);

            if ($existing_change) {
                $existing_change->update([
                    'new_value' => $change['new_value'] ?? null,
                    'new_label' => $change['new_label'] ?? null,
                ]);

                continue;
            }

            $activity->fieldChanges->push(
                $this->writeChange($activity->id, $change)
            );
        }

        $activity->load('fieldChanges');
        $activity->update(['description' => $this->describe($activity)]);
    }

    /** @param array<string, mixed> $change */
    private function writeChange(int $activity_id, array $change): ActivityChange
    {
        return ActivityChange::create([
            'activity_id' => $activity_id,
            'field' => $change['field'],
            'label' => $change['label'] ?? null,
            'old_value' => $change['old_value'] ?? null,
            'new_value' => $change['new_value'] ?? null,
            'old_label' => $change['old_label'] ?? null,
            'new_label' => $change['new_label'] ?? null,
        ]);
    }

    private function describe(Activity $activity): string
    {
        $parts = $activity->fieldChanges
            ->filter(fn (ActivityChange $change) => $change->old_label !== $change->new_label)
            ->map(fn (ActivityChange $change) => $change->label . ' gewijzigd van \''
                . ($change->old_label ?? '-') . '\' naar \'' . ($change->new_label ?? '-') . '\'')
            ->all();

        return implode(', ', $parts);
    }

    /**
     * Keeps the existing pivot behaviour so an entry can also surface on related
     * records, which is what the timelines on the show pages read today.
     */
    private function attachToTimelines(Activity $activity, Signal $signal): void
    {
        $records = array_merge([$signal->subject()], $signal->activityContext());

        foreach ($records as $record) {
            if (!method_exists($record, 'activities') || !$record->exists) {
                continue;
            }

            $record->activities()->attach($activity->id);
        }
    }
}
