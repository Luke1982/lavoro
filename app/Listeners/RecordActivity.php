<?php

namespace App\Listeners;

use App\Domain\Signals\ActivityBuffer;
use App\Domain\Signals\ChangeSentence;
use App\Domain\Signals\Signal;
use App\Models\Activity;
use App\Models\ActivityChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes the log entry for any signal. Registered by Laravel's listener
 * discovery through the Signal type hint, so every present and future signal is
 * covered without touching this class or registering anything by hand.
 *
 * It runs inside the transaction that raised the signal, so a rollback takes the
 * entry with it. The activity is written directly rather than through the subject
 * model: a deleted record no longer exists, so only its type and id are safe to keep.
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
            'via' => $signal->via(),
            'metadata' => $signal->activityMetadata(),
            'occurred_at' => $signal->occurredAt(),
            'correlation_id' => $signal->correlationId(),
            'required_permission' => $this->requiredPermission($signal),
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
        return ChangeSentence::of($activity->fieldChanges
            ->filter(fn (ActivityChange $change) => $change->old_label !== $change->new_label)
            ->map(fn (ActivityChange $change) => $change->only(['label', 'old_label', 'new_label'])));
    }

    /**
     * A fact about a record whose existence is gated -- a quote, a contract -- is
     * gated the same way when the signal names no permission of its own, because
     * it also lands on the customer's timeline, which more people may read.
     */
    private function requiredPermission(Signal $signal): ?string
    {
        $subject = $signal->subject();

        return $signal->requiredPermission()
            ?? (method_exists($subject, 'historyPermission') ? $subject->historyPermission() : null);
    }

    /**
     * Attaches the entry to its subject and to every record it also belongs on,
     * once each and in a single insert per thousand: a fact that lands on every
     * product holding a kenmerk would otherwise cost a query per product.
     */
    private function attachToTimelines(Activity $activity, Signal $signal): void
    {
        $rows = [];
        $now = now();

        foreach ([$signal->subject(), ...$signal->activityContext()] as $record) {
            if (!method_exists($record, 'activities') || !$record->exists) {
                continue;
            }

            $rows[$record->getMorphClass() . '|' . $record->getKey()] = [
                'activity_id' => $activity->id,
                'activityable_type' => $record->getMorphClass(),
                'activityable_id' => $record->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($rows), 1000) as $chunk) {
            DB::table('activityables')->insert($chunk);
        }
    }
}
