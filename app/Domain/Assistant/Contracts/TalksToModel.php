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
     * @param  array<int, Turn>  $turns
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>, strict: bool}>  $tools
     */
    public function send(array $turns, array $tools, string $system): ModelReply;
}
