<?php

namespace App\Services\Push;

/**
 * A way to put a message on somebody's screen while the app is shut.
 *
 * Best effort by design. A message that cannot be delivered is logged, an
 * address that is gone for good is deleted, and neither stops the rest: the
 * in-app notification is already written and waiting either way.
 */
interface PushChannel
{
    public function isConfigured(): bool;

    /**
     * @param  array<int, list<PushMessage>>  $batches  the messages per user id
     */
    public function send(array $batches): void;
}
