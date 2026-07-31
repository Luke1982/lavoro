<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What every tool has to hold to regardless of what it does.
 *
 * These are the promises the rest of the assistant is built on, and each one is
 * the sort that fails quietly. A write tool that forgets to ask changes something
 * without a button ever appearing. A schema that says a field is required when no
 * such field exists is refused by one supplier and ignored by another. None of it
 * shows up as an error; it shows up as a werkbon nobody meant to make.
 */
class ToolContractTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /** @return array<int, class-string<Tool>> */
    private function tools(): array
    {
        return config('assistant.tools', []);
    }

    /**
     * The namespace is the claim and requiresConfirmation is the enforcement, so
     * they have to agree. A tool under Write that does not ask is the one bug in
     * this application nobody would catch by using it — it would simply work,
     * silently, without permission.
     */
    public function test_everything_that_writes_asks_first_and_nothing_else_does(): void
    {
        $wrong = [];

        foreach ($this->tools() as $class) {
            $writes = str_contains($class, '\\Tools\\Write\\');
            $asks = app($class)->requiresConfirmation();

            if ($writes !== $asks) {
                $wrong[] = $class::name() . ($writes ? ' writes but never asks' : ' asks but writes nothing');
            }
        }

        $this->assertSame([], $wrong, implode('; ', $wrong));
    }

    /**
     * The button sits under a paragraph that is halfway up the box by the time
     * somebody has asked two more things, so it has to say what it will do.
     */
    public function test_everything_that_asks_can_describe_what_it_would_do(): void
    {
        $silent = [];

        foreach ($this->tools() as $class) {
            $tool = app($class);

            if ($tool->requiresConfirmation() && !$tool instanceof Confirmable) {
                $silent[] = $class::name();
            }
        }

        $this->assertSame([], $silent, 'these would offer a button labelled nothing: ' . implode(', ', $silent));
    }

    public function test_no_schema_demands_a_field_it_does_not_have(): void
    {
        $broken = [];

        foreach ($this->tools() as $class) {
            $schema = app($class)->inputSchema();
            $ghosts = array_diff($schema['required'] ?? [], array_keys($schema['properties'] ?? []));

            foreach ($ghosts as $ghost) {
                $broken[] = $class::name() . ' requires ' . $ghost;
            }
        }

        $this->assertSame([], $broken, implode('; ', $broken));
    }

    /**
     * Arguments arrive from a language model, so a field with no explanation is a
     * field it will fill in wrongly or not at all.
     */
    public function test_every_field_explains_itself(): void
    {
        $mute = [];

        foreach ($this->tools() as $class) {
            $tool = app($class);

            if (blank($tool->description())) {
                $mute[] = $class::name() . ' (the tool itself)';
            }

            foreach ($tool->inputSchema()['properties'] ?? [] as $field => $spec) {
                if (blank($spec['description'] ?? null)) {
                    $mute[] = $class::name() . '.' . $field;
                }

                if (($spec['type'] ?? null) === 'array' && !isset($spec['items'])) {
                    $mute[] = $class::name() . '.' . $field . ' is a list of nothing in particular';
                }
            }
        }

        $this->assertSame([], $mute, implode('; ', $mute));
    }

    /**
     * Strict validation is off, so nothing stops a model sending a field nobody
     * declared. Saying so in the schema is what keeps the suppliers that do check
     * from refusing the request outright.
     */
    public function test_no_schema_invites_fields_nobody_declared(): void
    {
        foreach ($this->tools() as $class) {
            $this->assertFalse(
                app($class)->inputSchema()['additionalProperties'] ?? true,
                $class::name() . ' accepts undeclared fields'
            );
        }
    }

    public function test_every_tool_is_reachable_by_somebody(): void
    {
        foreach ($this->tools() as $class) {
            $this->assertNotEmpty($class::availableTo(), $class::name() . ' is offered to nobody at all');
        }
    }

    /**
     * Two tools answering to one name means the registry silently keeps whichever
     * came last, and the other simply never runs.
     */
    public function test_no_two_tools_share_a_name(): void
    {
        $names = array_map(fn (string $class) => $class::name(), $this->tools());

        $this->assertSame(
            array_unique($names),
            $names,
            'duplicate names: ' . implode(', ', array_diff_assoc($names, array_unique($names)))
        );
    }

    /**
     * What a tool reads and what it declares have to be the same list.
     *
     * This became load-bearing when arguments started being checked against the
     * schema at the door: read something the schema does not declare and every
     * real call carrying it is refused, while nothing here goes red, because the
     * tests pass exactly the arguments the tool was written to expect. The other
     * direction is only untidy — a declared argument nothing reads is an option
     * offered to the model that quietly does nothing.
     */
    public function test_every_tool_reads_exactly_what_it_declares(): void
    {
        $reader = '/(?:string|integer|integerList|date|like|boolean)?[Aa]rgument\(\s*[\'"]([a-z_]+)[\'"]|wasGiven\(\s*[\'"]([a-z_]+)[\'"]/';

        foreach ($this->tools() as $class) {
            $tool = app($class);
            $source = file_get_contents((new \ReflectionClass($tool))->getFileName());

            preg_match_all($reader, $source, $found);

            $read = array_values(array_unique(array_filter(array_merge($found[1], $found[2]))));
            $declared = array_keys($tool->inputSchema()['properties'] ?? []);

            $this->assertSame(
                [],
                array_values(array_diff($read, $declared)),
                $tool::name() . ' reads an argument it never declares, so every call carrying it is refused',
            );

            $this->assertSame(
                [],
                array_values(array_diff($declared, $read)),
                $tool::name() . ' offers the model an argument it never reads',
            );
        }
    }

    /**
     * A tool that stops at a ceiling has to say so.
     *
     * Six of them reported the slice as the answer — "25 storingen gevonden" with
     * three hundred and sixty in the table — and the fix went in one tool at a
     * time, which is exactly how the other five stayed broken for a fortnight. A
     * seventh written next month would go the same way, so the rule is checked
     * rather than remembered.
     */
    public function test_a_tool_that_caps_its_results_reports_the_whole_count(): void
    {
        $forgetful = [];

        foreach ($this->tools() as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            $caps = str_contains($source, 'limit($limit)')
                || str_contains($source, "limit((int) config('assistant.max_results'");

            if ($caps && !str_contains($source, 'ReportsTheWholeCount')) {
                $forgetful[] = $class::name();
            }
        }

        $this->assertSame([], $forgetful, 'these cap their results and call the slice a total: '
            . implode(', ', $forgetful));
    }
}
