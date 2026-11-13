<?php

namespace Tests\Feature\TaskChecks;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\Write\AddServiceOrderTaskTool;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskChecklist;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskChecks\PickedExtras;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What a werkbon task gets from its template when it is created: the checks of
 * its checklists and its loose checks, each once, with the required flag the
 * links give it, per machine or for the whole task, plus the extras picked.
 */
class CopyChecksTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrderTask $task;

    private ServiceOrderTaskChecklist $list;

    private array $checks = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['A', 'B', 'C', 'D'] as $title) {
            $this->checks[$title] = ServiceOrderTaskCheck::create(['title' => $title]);
        }

        $this->list = ServiceOrderTaskChecklist::create(['title' => 'Checklist oplevering']);
        $this->list->checks()->attach($this->checks['A']->id, ['is_required' => true, 'sort_order' => 1]);
        $this->list->checks()->attach($this->checks['B']->id, ['is_required' => false, 'sort_order' => 2]);

        $this->task = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $this->task->checklists()->attach($this->list->id, ['is_required' => true, 'sort_order' => 1]);
        $this->task->checks()->attach($this->checks['B']->id, ['is_required' => false, 'sort_order' => 1]);
        $this->task->checks()->attach($this->checks['C']->id, ['is_required' => true, 'sort_order' => 2]);
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function create(array $attributes = [], ?PickedExtras $extras = null): ServiceOrderTaskInstance
    {
        return app(CreateTaskInstanceAction::class)->execute([
            'service_order_id' => $this->order()->id,
            'service_order_task_id' => $this->task->id,
        ] + $attributes, $extras);
    }

    private function required(ServiceOrderTaskInstance $instance): array
    {
        return $instance->checks()->get()->mapWithKeys(fn ($check) => [$check->title => $check->is_required])->all();
    }

    public function test_a_check_through_an_optional_checklist_is_optional(): void
    {
        $this->task->checklists()->updateExistingPivot($this->list->id, ['is_required' => false]);

        $instance = $this->create();

        $this->assertSame(['A' => false, 'B' => false, 'C' => true], $this->required($instance));
    }

    public function test_a_check_reached_twice_is_copied_once_and_required_if_any_route_is(): void
    {
        $this->task->checks()->updateExistingPivot($this->checks['B']->id, ['is_required' => true]);

        $instance = $this->create();
        $copies = $instance->checks()->where('title', 'B')->get();

        $this->assertCount(1, $copies);
        $this->assertTrue($copies->first()->is_required);
        $this->assertSame('Checklist oplevering', $copies->first()->checklist_title);
        $this->assertSame(['A', 'B', 'C'], $instance->checks()->pluck('title')->all());
    }

    public function test_per_machine_copies_one_set_per_unit(): void
    {
        $instance = $this->create([
            'product_id' => Product::factory()->create()->id,
            'quantity' => 3,
            'checks_per_unit' => true,
        ]);

        $units = $instance->checks()->get()->groupBy('unit_index')->map->pluck('title')->map->all()->all();

        $this->assertSame([0 => ['A', 'B', 'C'], 1 => ['A', 'B', 'C'], 2 => ['A', 'B', 'C']], $units);
    }

    public function test_the_whole_task_gets_one_set_without_a_unit(): void
    {
        $instance = $this->create([
            'product_id' => Product::factory()->create()->id,
            'quantity' => 3,
        ]);

        $this->assertSame(3, $instance->checks()->count());
        $this->assertSame(0, $instance->checks()->whereNotNull('unit_index')->count());
    }

    public function test_extras_are_optional_and_skip_checks_already_copied(): void
    {
        $instance = $this->create([], new PickedExtras(
            [$this->checks['C']->id, $this->checks['D']->id],
            [],
            ['Foto gemaakt'],
        ));

        $rows = $instance->checks()->get()->keyBy('title');

        $this->assertSame(['A', 'B', 'C', 'D', 'Foto gemaakt'], $rows->keys()->all());
        $this->assertTrue($rows['C']->is_required);
        $this->assertFalse($rows['C']->is_extra);
        $this->assertFalse($rows['D']->is_required);
        $this->assertTrue($rows['D']->is_extra);
        $this->assertTrue($rows['Foto gemaakt']->is_extra);
        $this->assertNull($rows['Foto gemaakt']->service_order_task_check_id);
    }

    public function test_a_task_with_required_checks_cannot_be_created_complete(): void
    {
        $user = $this->userWithPermissions('serviceordertaskinstance.create');

        $this->actingAs($user)->post('/serviceordertaskinstances', [
            'service_order_id' => $this->order()->id,
            'service_order_task_id' => $this->task->id,
            'is_complete' => true,
        ])->assertSessionHasErrors('is_complete');

        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_the_form_creates_the_task_with_its_checks(): void
    {
        $user = $this->userWithPermissions('serviceordertaskinstance.create');

        $this->actingAs($user)->post('/serviceordertaskinstances', [
            'service_order_id' => $this->order()->id,
            'service_order_task_id' => $this->task->id,
            'extra_titles' => ['Foto gemaakt'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['A', 'B', 'C', 'Foto gemaakt'],
            ServiceOrderTaskInstance::sole()->checks()->pluck('title')->all()
        );
    }

    public function test_the_assistant_tool_copies_checks(): void
    {
        $user = $this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read', 'product.read');
        $arguments = [
            'service_order_id' => $this->order()->id,
            'description' => 'Airco plaatsen',
            'service_order_task_id' => $this->task->id,
        ];

        $result = app(ToolExecutor::class)->run(new ToolCall(
            AddServiceOrderTaskTool::name(),
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for(AddServiceOrderTaskTool::name(), $arguments, $user)->encoded(),
        ));

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame(['A', 'B', 'C'], ServiceOrderTaskInstance::sole()->checks()->pluck('title')->all());
    }
}
