<?php

namespace App\Domain\Tools;

use App\Models\User;

/**
 * One business capability the assistant may use.
 *
 * A tool is the only way the assistant reaches the application. It holds no
 * business logic of its own: it describes itself to the model, decides whether
 * this user may invoke it, and delegates to an Action. Anything it does beyond
 * that belongs somewhere else.
 *
 * One tool is one capability. The moment a tool starts sequencing steps it has
 * become a workflow, and workflows belong in Actions where they can be tested
 * without a model in the loop.
 */
interface Tool
{
    /** Stable machine name the model calls. Never translated, never reused. */
    public static function name(): string;

    /**
     * What the tool does and, more importantly, when to reach for it. The model
     * decides from this sentence alone, so it states the trigger condition and
     * not only the capability.
     */
    public function description(): string;

    /**
     * JSON Schema for the arguments. Sent with strict validation enabled, so the
     * arguments that arrive are guaranteed to match this shape and no tool needs
     * to re-check it.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Whether this user may perform this capability, on these specific records.
     *
     * The arguments are part of the question: permission to update a werkbon is
     * not permission to update *this* werkbon. Implementations answer through
     * policies only — hasPermission belongs in the policy, not here.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function authorize(User $user, array $arguments): bool;

    /**
     * Whether execution must be held until the person explicitly agrees. This is
     * a declaration, not the enforcement: the executor refuses to run a tool that
     * says true without a confirmation the user actually gave.
     */
    public function requiresConfirmation(): bool;

    public function execute(ToolCall $call): ToolResult;

    /**
     * How hard this tool is to use well, from 1 to 10.
     *
     * It rates the judgement needed to call it — reading a question, choosing
     * this tool over another, filling its arguments — and not how complicated
     * the tool is inside. A tool doing heavy work behind a single obvious
     * argument is easy; one with six optional filters that must be inferred from
     * a sentence is not.
     *
     * A conversation is answered by the cheapest model that clears the hardest
     * tool the person is offered, so an inflated rating makes every question
     * that user asks dearer, not only the ones reaching this tool.
     */
    public static function difficulty(): int;

    /**
     * Which tool profiles are told this tool exists.
     *
     * This shapes what the model knows about, never what it may do — authorize()
     * is the boundary. Profiles exist so that everyone sharing one gets the same
     * tool list, and therefore the same cacheable prompt prefix.
     *
     * @return array<int, ToolProfile>
     */
    public static function availableTo(): array;
}
