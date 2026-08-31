<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class PermBoundaryTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_a_user_with_nothing_gets_nothing_from_any_tool(): void
    {
        $nobody = $this->userWith('assistant.use');
        $customer = Customer::factory()->create(['name' => 'Geheim BV']);
        ServiceOrder::factory()->create(['customer_id' => $customer->id]);

        $leaked = [];

        foreach (app(ToolRegistry::class)->all() as $class) {
            $tool = is_string($class) ? app($class) : $class;

            $result = app(ToolExecutor::class)->run(new ToolCall($tool::name(), [
                'query' => 'Geheim', 'customer_id' => $customer->id, 'city' => 'x',
                'id' => $customer->id, 'ticket_id' => 1, 'subject_type' => 'werkbon',
            ], $nobody));

            if (!$result->is_error && str_contains(json_encode($result->content), 'Geheim')) {
                $leaked[] = $tool::name();
            }
        }

        $this->assertSame([], $leaked, 'these returned data to somebody with no permissions: ' . implode(', ', $leaked));
    }

    public function test_the_offered_tool_list_shrinks_with_the_person(): void
    {
        $registry = app(ToolRegistry::class);

        $technician = $this->userWithPermissions('assistant.use', 'serviceorder.read_own');
        /** A planner is whoever may see or plan other people's diaries — see ToolProfile. */
        $planner = $this->userWithPermissions('assistant.use', 'event.see_all');

        $this->assertLessThan(
            count($registry->definitionsFor($planner)),
            count($registry->definitionsFor($technician)),
            'a technician is offered as much as a planner',
        );
    }
}
