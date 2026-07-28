<?php

namespace App\Actions\Appointments;

/**
 * Who is executing an appointment, and under which conditions.
 *
 * The four arrays travel together because they are four views of one decision:
 * the ids say who, and the other three are keyed by those same ids. Passing
 * them separately is how they drift apart.
 */
final class AppointmentAssignment
{
    /**
     * @param  array<int, int>  $user_ids
     * @param  array<int, int>  $breaktimes
     * @param  array<int, array<int, int>>  $user_roles
     * @param  array<int, array<string, mixed>>  $diverging_times
     */
    public function __construct(
        public readonly array $user_ids = [],
        public readonly array $breaktimes = [],
        public readonly array $user_roles = [],
        public readonly array $diverging_times = [],
    ) {}

    /**
     * Builds an assignment from a raw payload, casting the ids the way the
     * pivot expects them. Returns null when the payload says nothing about
     * assignment at all, which is different from saying "nobody".
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        if (!array_key_exists('executing_user_ids', $payload)) {
            return null;
        }

        $user_ids = $payload['executing_user_ids'];

        if (!is_array($user_ids)) {
            return null;
        }

        return new self(
            user_ids: array_map('intval', $user_ids),
            breaktimes: array_map('intval', (array) ($payload['executing_user_breaktimes'] ?? [])),
            user_roles: (array) ($payload['executing_user_roles'] ?? []),
            diverging_times: (array) ($payload['executing_user_diverging_times'] ?? []),
        );
    }

    public function isEmpty(): bool
    {
        return $this->user_ids === [];
    }
}
