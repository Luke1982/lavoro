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

    public function existingFor(
        string $subject_type,
        int|string|null $subject_id,
        string $action,
        string $actor_type = 'user',
    ): ?Activity {
        $key = $this->key($subject_type, $subject_id, $action, $actor_type);

        if (!isset($this->entries[$key])) {
            return null;
        }

        return Activity::with('fieldChanges')->find($this->entries[$key]);
    }

    public function remember(
        string $subject_type,
        int|string|null $subject_id,
        string $action,
        int $activity_id,
        string $actor_type = 'user',
    ): void {
        $this->entries[$this->key($subject_type, $subject_id, $action, $actor_type)] = $activity_id;
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    /**
     * The actor is part of the key so that two different actors touching one
     * record in a single request keep separate entries. Without it, work the
     * assistant did would fold into the line written for the person who acted
     * just before it, and the log would credit them with it.
     */
    private function key(string $subject_type, int|string|null $subject_id, string $action, string $actor_type): string
    {
        return $subject_type . '|' . $subject_id . '|' . $action . '|' . $actor_type;
    }
}
