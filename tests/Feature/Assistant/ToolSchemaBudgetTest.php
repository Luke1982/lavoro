<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The tool definitions are sent on every single question.
 *
 * They sit behind the cache marker so they are usually read rather than
 * rewritten, but they are still the largest fixed thing in every request, and
 * nothing about adding a tool makes that visible. This is a tripwire, not a
 * rule: if it fails, look at whether the newest tool needs everything it asks
 * for, then move the number.
 *
 * The old version of this guarded a hard limit of twenty-four optional
 * parameters, which the supplier enforces only under strict validation. That is
 * off now — deliberately, because the limit cost real capability and never
 * caught a real fault — so what is left to watch is size.
 */
class ToolSchemaBudgetTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /**
     * Seventeen tools. Raised four times now — 12000, 14000, 16000, 18000,
     * 19000 — each time for something the assistant could not work without,
     * most recently the manual of the application itself, so questions about
     * how Lavoro works stopped being answered from a model's general idea of
     * what a field-service system probably does.
     *
     * The bytes themselves cost little, since these sit behind the cache marker
     * and are read rather than rewritten. What is worth watching at seventeen is
     * not the size but the choosing: every tool added is one more thing to pick
     * wrongly from, and that shows up as a bad answer rather than a bill.
     */
    private const ROOM_FOR = 27000;

    public function test_the_tool_definitions_do_not_quietly_balloon(): void
    {
        /** Counted for an admin: the widest set anybody can be offered. */
        $definitions = app(ToolRegistry::class)->definitionsFor($this->admin());

        $sizes = [];

        foreach ($definitions as $definition) {
            $sizes[$definition['name']] = strlen(json_encode($definition));
        }

        arsort($sizes);
        $total = array_sum($sizes);

        $breakdown = implode(', ', array_map(
            fn (string $name, int $size) => $name . ': ' . $size,
            array_keys($sizes),
            $sizes,
        ));

        $this->assertLessThanOrEqual(
            self::ROOM_FOR,
            $total,
            'The tool definitions come to ' . $total . ' bytes and go out with every question. '
            . 'Largest first — ' . $breakdown,
        );
    }

    /**
     * Strict is what ties the schemas to a hard ceiling, so turning it back on
     * without reading why it went off would break every question at once.
     */
    public function test_strict_validation_stays_off(): void
    {
        foreach (app(ToolRegistry::class)->definitionsFor($this->admin()) as $definition) {
            $this->assertFalse($definition['strict'], $definition['name']);
        }
    }
}
