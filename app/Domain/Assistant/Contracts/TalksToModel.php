<?php

namespace App\Domain\Assistant\Contracts;

/**
 * One turn of conversation with whichever model is configured.
 *
 * Nothing supplier-specific crosses this line in either direction: turns and
 * tool definitions go in, a ModelReply comes back. Changing supplier is writing
 * one implementation of this and pointing a config value at it.
 *
 * What an implementation owns is everything the suppliers disagree about — how
 * tools are described, how caching is asked for, how reasoning is replayed, what
 * their stop reasons are called.
 */
interface TalksToModel
{
    /**
     * Whether this supplier will read a file that travels with a question.
     *
     * Asked rather than assumed, because the answer differs per supplier and the
     * whole point of this layer is that one can be swapped for another. A tool
     * that wants to show the model a datasheet checks first and says plainly that
     * it cannot, instead of sending something that gets quietly dropped.
     */
    public function readsDocuments(): bool;

    /**
     * Whether this model can look at a photo.
     *
     * Asked separately from documents because they are separate abilities and
     * one adapter has one without the other. A tool that hands back images to a
     * model that cannot see them fails silently — the answer arrives describing
     * what was never looked at.
     */
    public function seesImages(): bool;

    /**
     * @param  array<int, Turn>  $turns
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>, strict: bool}>  $tools
     */
    public function send(array $turns, array $tools, string $system): ModelReply;
}
