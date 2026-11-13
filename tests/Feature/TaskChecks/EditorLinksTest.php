<?php

namespace Tests\Feature\TaskChecks;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskCheckLink;
use App\Models\ServiceOrderTaskChecklist;
use App\Models\ServiceOrderTaskChecklistLink;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The checklist editor's links: a check sits once in a list or on a task, the
 * client names the kind of target and never a class, an order is only ever
 * within one list, and deleting from the library leaves running werkbonnen alone.
 */
class EditorLinksTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->userWithPermissions('serviceordertask.read', 'serviceordertask.update');
    }

    public function test_the_editor_page_lists_checks_lists_and_tasks(): void
    {
        $check = ServiceOrderTaskCheck::create(['title' => 'Rooster schoongemaakt']);
        $task = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $task->checks()->attach($check->id, ['is_required' => true, 'sort_order' => 1]);

        $this->actingAs($this->manager)->get('/serviceordertaskchecks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceOrderTaskChecks/EditorPage')
                ->where('checks.0.title', 'Rooster schoongemaakt')
                ->where('tasks.0.check_links.0.check_id', $check->id)
                ->where('tasks.0.check_links.0.is_required', true));
    }

    public function test_linking_a_check_twice_is_refused(): void
    {
        $check = ServiceOrderTaskCheck::create(['title' => 'A']);
        $list = ServiceOrderTaskChecklist::create(['title' => 'L']);
        $payload = ['check_id' => $check->id, 'target' => 'checklist', 'target_id' => $list->id];

        $this->actingAs($this->manager)->post('/serviceordertaskchecklinks', $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/serviceordertaskchecklinks', $payload)
            ->assertSessionHasErrors(['check_id' => 'Deze check zit hier al in.']);

        $this->assertSame(1, ServiceOrderTaskCheckLink::count());
    }

    public function test_the_target_name_maps_to_the_model(): void
    {
        $check = ServiceOrderTaskCheck::create(['title' => 'A']);
        $list = ServiceOrderTaskChecklist::create(['title' => 'L']);

        $this->actingAs($this->manager)->post('/serviceordertaskchecklinks', [
            'check_id' => $check->id, 'target' => 'checklist', 'target_id' => $list->id,
        ])->assertSessionHasNoErrors();

        $link = ServiceOrderTaskCheckLink::sole();
        $this->assertSame(ServiceOrderTaskChecklist::class, $link->checkable_type);
        $this->assertTrue($link->is_required);

        $this->actingAs($this->manager)->post('/serviceordertaskchecklinks', [
            'check_id' => $check->id, 'target' => User::class, 'target_id' => $this->manager->id,
        ])->assertSessionHasErrors('target');
    }

    public function test_reorder_sets_the_order_within_one_list(): void
    {
        $list = ServiceOrderTaskChecklist::create(['title' => 'L']);
        $links = collect(['A', 'B', 'C'])->map(fn ($title, $i) => ServiceOrderTaskCheckLink::create([
            'service_order_task_check_id' => ServiceOrderTaskCheck::create(['title' => $title])->id,
            'checkable_type' => ServiceOrderTaskChecklist::class,
            'checkable_id' => $list->id,
            'sort_order' => $i + 1,
        ]));

        $this->actingAs($this->manager)->post('/serviceordertaskchecklinks/reorder', [
            'ids' => [$links[2]->id, $links[0]->id, $links[1]->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['C', 'A', 'B'], $list->checks()->pluck('title')->all());
    }

    public function test_reorder_refuses_links_of_different_owners(): void
    {
        $check = ServiceOrderTaskCheck::create(['title' => 'A']);
        $first = ServiceOrderTaskCheckLink::create([
            'service_order_task_check_id' => $check->id,
            'checkable_type' => ServiceOrderTaskChecklist::class,
            'checkable_id' => ServiceOrderTaskChecklist::create(['title' => 'L1'])->id,
        ]);
        $second = ServiceOrderTaskCheckLink::create([
            'service_order_task_check_id' => $check->id,
            'checkable_type' => ServiceOrderTaskChecklist::class,
            'checkable_id' => ServiceOrderTaskChecklist::create(['title' => 'L2'])->id,
        ]);

        $this->actingAs($this->manager)->post('/serviceordertaskchecklinks/reorder', [
            'ids' => [$second->id, $first->id],
        ])->assertSessionHasErrors('ids');
    }

    public function test_a_checklist_on_a_task_can_be_made_optional(): void
    {
        $list = ServiceOrderTaskChecklist::create(['title' => 'L']);
        $task = ServiceOrderTask::create(['title' => 'T']);

        $this->actingAs($this->manager)->post('/serviceordertaskchecklistlinks', [
            'checklist_id' => $list->id, 'task_id' => $task->id,
        ])->assertSessionHasNoErrors();

        $link = ServiceOrderTaskChecklistLink::sole();
        $this->assertTrue($link->is_required);

        $this->actingAs($this->manager)->patch("/serviceordertaskchecklistlinks/{$link->id}", ['is_required' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($link->fresh()->is_required);

        $this->actingAs($this->manager)->post('/serviceordertaskchecklistlinks', [
            'checklist_id' => $list->id, 'task_id' => $task->id,
        ])->assertSessionHasErrors(['checklist_id' => 'Deze checklist zit al in deze taak.']);
    }

    public function test_deleting_a_check_keeps_the_werkbon_copies(): void
    {
        $check = ServiceOrderTaskCheck::create(['title' => 'Rooster schoongemaakt']);
        $task = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $task->checks()->attach($check->id, ['is_required' => true, 'sort_order' => 1]);
        $instance = app(CreateTaskInstanceAction::class)->execute([
            'service_order_id' => ServiceOrder::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'service_order_task_id' => $task->id,
        ]);

        $this->actingAs($this->manager)->delete("/serviceordertaskchecks/{$check->id}")->assertSessionHasNoErrors();

        $copy = $instance->checks()->sole();
        $this->assertSame('Rooster schoongemaakt', $copy->title);
        $this->assertNull($copy->service_order_task_check_id);
        $this->assertTrue($copy->is_required);
        $this->assertSame(0, ServiceOrderTaskCheckLink::count());
    }

    public function test_the_editor_needs_the_task_permissions(): void
    {
        $reader = $this->userWithPermissions('serviceordertask.read');

        $this->actingAs($reader)->get('/serviceordertaskchecks')->assertOk();
        $this->actingAs($reader)->postJson('/serviceordertaskchecks', ['title' => 'A'])->assertForbidden();
        $this->actingAs($this->userWithPermissions('serviceorder.read'))->getJson('/serviceordertaskchecks')->assertForbidden();
        $this->assertSame(0, ServiceOrderTaskCheck::count());
    }

    public function test_deleting_a_checklist_or_task_takes_its_links_along(): void
    {
        $check = ServiceOrderTaskCheck::create(['title' => 'A']);
        $list = ServiceOrderTaskChecklist::create(['title' => 'L']);
        $task = ServiceOrderTask::create(['title' => 'T']);
        $list->checks()->attach($check->id, ['is_required' => true, 'sort_order' => 1]);
        $task->checks()->attach($check->id, ['is_required' => true, 'sort_order' => 1]);
        $task->checklists()->attach($list->id, ['is_required' => true, 'sort_order' => 1]);

        $this->actingAs($this->manager)->delete("/serviceordertaskchecklists/{$list->id}")->assertSessionHasNoErrors();
        $task->delete();

        $this->assertSame(0, ServiceOrderTaskCheckLink::count());
        $this->assertSame(0, ServiceOrderTaskChecklistLink::count());
    }
}
