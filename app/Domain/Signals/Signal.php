<?php

namespace App\Domain\Signals;

use Illuminate\Database\Eloquent\Model;

/**
 * A business fact that has happened. Listeners subscribe to this interface to
 * react to every signal, or to a concrete implementation to react to one.
 *
 * This is deliberately an interface and not an abstract class: Laravel's event
 * dispatcher resolves listeners through class_implements(), never through
 * class_parents(), so a shared base class would not be listenable.
 */
interface Signal
{
    /**
     * Stable machine key for the signal type. Never translated, never reused.
     */
    public static function key(): string;

    /**
     * The key written to the log for this particular occurrence, which for the
     * generic model signal varies by model and action ("serviceorder.updated").
     */
    public function eventKey(): string;

    public static function label(): string;

    public function subject(): Model;

    /** The Dutch line shown to a person, or null when this fact needs no sentence. */
    public function activityDescription(): ?string;

    public function activityCategory(): string;

    /** @return array<string, mixed>|null */
    public function activityMetadata(): ?array;

    /** @return array<int, Model> Extra records this should also appear on. */
    public function activityContext(): array;

    /**
     * One entry per changed field, each carrying the raw value and the readable
     * label, before and after.
     *
     * @return array<int, array<string, mixed>>
     */
    public function changes(): array;

    /**
     * Identifies repeat occurrences of the same action on the same record within
     * one request, which are folded into a single entry. Null never merges.
     */
    public function mergeKey(): ?string;

    /**
     * Model fields whose changes this signal reports itself. The automatic audit
     * trail must ignore them, or the same change is logged twice.
     *
     * @return array<int, string>
     */
    public static function coveredFields(): array;

    public function actorId(): ?int;

    public function actorType(): string;

    public function actorName(): ?string;

    public function occurredAt(): \DateTimeImmutable;
}
