<?php

namespace App\Domain\Tools\Write\Concerns;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolResult;
use WeakMap;

/**
 * One method that checks a proposal and works out what carrying it out needs.
 *
 * Run before the button is shown and again on the click, so the two checks can
 * never disagree, and the click does not check twice what it is about to use.
 */
trait ResolvesBeforeConfirming
{
    /** @var ?WeakMap<ToolCall, ToolResult|array<string, mixed>> */
    private ?WeakMap $resolutions = null;

    /**
     * What carrying the proposal out needs, or why it cannot be.
     *
     * @return ToolResult|array<string, mixed>
     */
    abstract private function resolve(ToolCall $call): ToolResult|array;

    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        $resolved = $this->resolution($call);

        return $resolved instanceof ToolResult ? $resolved : null;
    }

    /**
     * resolve(), worked out once per call.
     *
     * The refusal, the preview and the work itself each need it, and for a
     * selection of contracts it is hundreds of rows and a formula per contract.
     * Kept per call rather than per tool: the click is a new call, so it never
     * reuses what was worked out when the button was shown.
     *
     * @return ToolResult|array<string, mixed>
     */
    private function resolution(ToolCall $call): ToolResult|array
    {
        $this->resolutions ??= new WeakMap;

        return $this->resolutions[$call] ??= $this->resolve($call);
    }
}
