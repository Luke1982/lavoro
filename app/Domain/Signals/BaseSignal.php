<?php

namespace App\Domain\Signals;

use App\Domain\Assistant\AssistantContext;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Shared boilerplate for signals.
 *
 * Signals fire immediately, inside whatever transaction produced them, so a
 * listener that writes to the database is atomic with the work it reacts to and
 * a rollback takes its log line with it. Listeners that reach outside the
 * database implement ShouldHandleEventsAfterCommit instead, because an e-mail
 * cannot be rolled back.
 *
 * The actor is resolved once, at the moment the fact occurs, and the actor's name
 * is copied rather than referenced so a deleted user does not erase their own
 * history. A queue job or console command is recorded as such, never as a person.
 *
 * Work the assistant did is recorded as 'ai' while still naming the person who
 * asked for it: they remain accountable and it belongs on their timeline, so only
 * actor_type separates "Jan changed this" from "the assistant changed this for Jan".
 *
 * Properties are intentionally not readonly: SerializesModels reassigns them by
 * reflection when a queued listener unserialises the signal, which throws on an
 * already initialised readonly property.
 */
abstract class BaseSignal implements Signal
{
    use SerializesModels;

    /**
     * Defaults are set on the properties themselves, not only in the constructor,
     * so a subclass that forgets to call parent::__construct() records an unknown
     * actor instead of throwing an uninitialised-property error at runtime.
     */
    public ?int $actor_id = null;

    public string $actor_type = 'system';

    public ?string $actor_name = null;

    public ?\DateTimeImmutable $occurred_at = null;

    public ?string $correlation_id = null;

    public function __construct()
    {
        $assistant = app(AssistantContext::class);
        $user = $assistant->onBehalfOf() ?? Auth::user();

        $this->actor_id = $user?->id;
        $this->actor_name = $user?->name;
        $this->actor_type = match (true) {
            $assistant->isActive() => 'ai',
            $user !== null => 'user',
            app()->runningInConsole() => 'system',
            default => 'system',
        };
        $this->occurred_at = new \DateTimeImmutable;
    }

    public static function label(): string
    {
        return static::key();
    }

    public function eventKey(): string
    {
        return static::key();
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function activityMetadata(): ?array
    {
        return null;
    }

    public function activityContext(): array
    {
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    public function changes(): array
    {
        return [];
    }

    public function mergeKey(): ?string
    {
        return null;
    }

    public static function coveredFields(): array
    {
        return [];
    }

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function correlateWith(string $correlation_id): void
    {
        $this->correlation_id = $correlation_id;
    }

    public function correlationId(): ?string
    {
        return $this->correlation_id;
    }

    public function actorId(): ?int
    {
        return $this->actor_id;
    }

    public function actorType(): string
    {
        return $this->actor_type;
    }

    public function actorName(): ?string
    {
        return $this->actor_name;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurred_at ??= new \DateTimeImmutable;
    }
}
