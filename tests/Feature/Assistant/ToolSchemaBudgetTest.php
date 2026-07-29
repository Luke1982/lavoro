<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * There is a ceiling on how many optional parameters all the tools may have
 * between them, and going over it does not degrade anything — the supplier
 * refuses the whole request with a 400 and every question stops working.
 *
 * It is an easy one to walk into: each tool is reasonable on its own, the budget
 * is shared, and nothing in the code hints that it exists. Adding one ordinary
 * tool with a handful of filters is enough. This is here so that lands as a
 * failing test rather than as an assistant that answers nothing in production.
 */
class ToolSchemaBudgetTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /** The supplier's own limit, quoted in the 400 it returns. */
    private const OPTIONAL_PARAMETER_LIMIT = 24;

    public function test_the_tools_fit_within_what_the_supplier_accepts(): void
    {
        /**
         * Counted for an admin on purpose: tools are offered per person, so the
         * widest set anybody can be given is the one that has to fit.
         */
        $definitions = app(ToolRegistry::class)->definitionsFor($this->admin());

        $spend = [];

        foreach ($definitions as $definition) {
            $properties = array_keys($definition['input_schema']['properties'] ?? []);
            $required = $definition['input_schema']['required'] ?? [];

            $spend[$definition['name']] = count(array_diff($properties, $required));
        }

        $total = array_sum($spend);

        $breakdown = implode(', ', array_map(
            fn (string $name, int $count) => $name . ': ' . $count,
            array_keys($spend),
            $spend,
        ));

        $this->assertLessThanOrEqual(
            self::OPTIONAL_PARAMETER_LIMIT,
            $total,
            'The tools ask for ' . $total . ' optional parameters between them and the limit is '
            . self::OPTIONAL_PARAMETER_LIMIT . '. Every question will fail with a 400 until this fits. '
            . 'Spend per tool — ' . $breakdown,
        );
    }
}
