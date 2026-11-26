<?php

namespace Tests\Feature\Planning;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The planner shows running projects as bars, with their unplanned werkbonnen
 * under them, only to whoever may read projects. Those werkbonnen never move to
 * the ordinary unplanned list.
 */
class PlannerProjectsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $customer = Customer::factory()->create();
        $project = Project::create([
            'title' => 'Nieuwbouw',
            'customer_id' => $customer->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'Niet gestart',
            'start_date' => today()->subDay(),
            'end_date' => today()->addMonth(),
        ]);
        $stage = ServiceOrderStage::create(['name' => 'Inplanbaar', 'order' => 1, 'is_plannable_state' => true]);
        $this->order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'service_order_stage_id' => $stage->id,
            'closed_on' => null,
        ]);
    }

    public function test_whoever_reads_projects_finds_the_werkbon_under_its_project(): void
    {
        $response = $this->actingAs($this->userWithPermissions('event.read', 'serviceorder.read', 'project.read'))->get(route('planner.index'));

        $project = collect($response->inertiaProps('projects'))->firstWhere('id', $this->order->project_id);
        $this->assertSame([$this->order->id], array_column($project['service_orders'], 'id'));
        $this->assertNotContains($this->order->id, collect($response->inertiaProps('unplannedServiceOrders'))->pluck('id'));
    }

    public function test_whoever_may_not_read_projects_sees_no_project_bars(): void
    {
        $response = $this->actingAs($this->userWithPermissions('event.read', 'serviceorder.read'))->get(route('planner.index'));

        $this->assertSame([], $response->inertiaProps('projects'));
        $this->assertNotContains($this->order->id, collect($response->inertiaProps('unplannedServiceOrders'))->pluck('id'));
    }

    public function test_a_project_bar_holds_only_the_werkbonnen_this_person_may_plan(): void
    {
        $response = $this->actingAs($this->userWithPermissions('event.read', 'serviceorder.read_own', 'project.read'))->get(route('planner.index'));

        $project = collect($response->inertiaProps('projects'))->firstWhere('id', $this->order->project_id);
        $this->assertSame([], $project['service_orders']);
    }
}
