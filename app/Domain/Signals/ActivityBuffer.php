<?php

namespace App\Domain\Signals;

use App\Models\Activity;

/**
 * Keeps one log entry per record per action for the life of a request.
 *
 * Without this, a single user action produces several entries, because this
 * codebase saves the same model more than once while handling one request and
 * every save reports itself. The first save creates the entry; later saves of
 * the same record merge into it instead of stacking up.
 *
 * Registered as a singleton and reset between queue jobs, so a long-running
 * worker never merges two unrelated jobs into one entry.
 */
class ActivityBuffer
{
    /** @var array<string, int> */
    private array $entries = [];

    public function existingFor(string $subject_type, int|string|null $subject_id, string $action): ?Activity
    {
        $key = $this->key($subject_type, $subject_id, $action);

        if (!isset($this->entries[$key])) {
            return null;
        }

        return Activity::with('fieldChanges')->find($this->entries[$key]);
    }

    public function remember(string $subject_type, int|string|null $subject_id, string $action, int $activity_id): void
    {
        $this->entries[$this->key($subject_type, $subject_id, $action)] = $activity_id;
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    private function key(string $subject_type, int|string|null $subject_id, string $action): string
    {
        return $subject_type . '|' . $subject_id . '|' . $action;
    }
}
