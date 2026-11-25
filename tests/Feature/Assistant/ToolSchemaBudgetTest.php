<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolRegistry;
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
     *
     * 34000 for turning a signed offerte into a werkbon: creating a customer, a
     * location and a maintenance contract, filing the offerte itself, and a
     * planner that keeps to the right group and weighs experience by product.
     *
     * 34500 for macros: reading a company's fixed werkbon routines and running
     * one through the same checks as the button on the werkbon.
     *
     * 35300 for the rondleidingen: one tool that puts a button under the answer
     * to show somebody how the planner works instead of telling them.
     *
     * 36800 for projects: finding one by its number and saying how it stands,
     * milestones and hours included, where before the assistant could only guess
     * at a project from its werkbonnen.
     *
     * 37000 for the rondleidingen that followed the planner's: the projects,
     * the maintenance contracts, the werkbonnen, the checklists and the macros,
     * each one more name and its chapters in what offer_tour can start.
     *
     * 41000 for price formulas: reading them, trying a draft on example
     * machines, saving one, and putting one on contracts, so somebody can say
     * or type how they want to charge and have the assistant build it.
     *
     * 41200 for history: search_activity names every record type that now has a
     * timeline -- machines, materials, suppliers, users, roles, settings and the
     * rest -- so "who changed this" has an answer for all of them.
     *
     * 41500 for named formula versions: saving a new version asks whether the
     * running contracts come along and what the version is called, instead of
     * leaving every contract behind without a word.
     *
     * 49000 for what the gap probe found missing on 2026-11-23: changing an
     * appointment, finding and changing contracts, booking material and hours,
     * quotes, and the planner's backlog. Eight tools, none over 925 bytes as the
     * registry sends them.
     */
    private const ROOM_FOR = 49000;

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
