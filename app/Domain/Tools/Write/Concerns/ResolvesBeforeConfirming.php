<?php

namespace App\Domain\Tools\Write\Concerns;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolResult;

/**
 * One method that checks a proposal and works out what carrying it out needs.
 *
 * Run before the button is shown and again on the click, so the two checks can
 * never disagree, and the click does not check twice what it is about to use.
 */
trait ResolvesBeforeConfirming
{
    /**
     * What carrying the proposal out needs, or why it cannot be.
     *
     * @return ToolResult|array<string, mixed>
     */
    abstract private function resolve(ToolCall $call): ToolResult|array;

    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        $resolved = $this->resolve($call);

        return $resolved instanceof ToolResult ? $resolved : null;
    }
}
