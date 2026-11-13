<?php

namespace Tests\Feature\TaskChecks;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Actions\Tasks\RegisterTaskAssetsAction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskChecklist;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskChecks\PickedExtras;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Editing a werkbon task keeps its checks in line with what it now covers:
 * machines added get their checks, machines dropped take theirs along unless
 * something on them is already ticked, and ticked work is never thrown away.
 */
class EditTaskChecksTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function task(int $quantity = 3, bool $per_unit = true, ?PickedExtras $extras = null): ServiceOrderTaskInstance
    {
        $template = ServiceOrderTask::create(['title' => 'Airco installatie']);
        $template->checks()->attach(ServiceOrderTaskCheck::create(['title' => 'Rooster schoongemaakt'])->id, [
            'is_required' => true,
            'sort_order' => 1,
        ]);

        return app(CreateTaskInstanceAction::class)->execute([
            'service_order_id' => ServiceOrder::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'service_order_task_id' => $template->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => $quantity,
            'checks_per_unit' => $per_unit,
        ], $extras);
    }

    private function tickUnit(ServiceOrderTaskInstance $instance, int $unit_index): void
    {
        $instance->checks()->where('unit_index', $unit_index)->update(['is_done' => true]);
    }

    private function edit(ServiceOrderTaskInstance $instance, array $data)
    {
        return $this->actingAs($this->userWithPermissions('serviceordertaskinstance.update'))
            ->patch("/serviceordertaskinstances/{$instance->id}", $data);
    }

    private function units(ServiceOrderTaskInstance $instance): array
    {
        return $instance->checks()->get()->pluck('unit_index')->unique()->values()->all();
    }

    public function test_raising_the_aantal_adds_a_set_for_the_new_machine(): void
    {
        $instance = $this->task(2);

        $this->edit($instance, ['quantity' => 3])->assertSessionHasNoErrors();

        $this->assertSame([0, 1, 2], $this->units($instance));
        $this->assertSame(1, $instance->checks()->where('unit_index', 2)->count());
    }

    public function test_lowering_the_aantal_removes_the_highest_free_unit(): void
    {
        $instance = $this->task(3);
        $this->tickUnit($instance, 2);

        $this->edit($instance, ['quantity' => 2])->assertSessionHasNoErrors();

        $this->assertSame([0, 1], $this->units($instance));
        $this->assertTrue($instance->checks()->where('unit_index', 1)->first()->is_done);
    }

    public function test_lowering_the_aantal_is_refused_when_ticked_units_would_go(): void
    {
        $instance = $this->task(3);
        $this->tickUnit($instance, 1);
        $this->tickUnit($instance, 2);

        $this->edit($instance, ['quantity' => 1])
            ->assertSessionHasErrors(['quantity' => 'Bij machine 3 zijn al checks afgevinkt; kies een hoger aantal.']);

        $this->assertSame(3, $instance->fresh()->quantity);
        $this->assertSame([0, 1, 2], $this->units($instance));
    }

    public function test_switching_per_machine_is_locked_after_a_tick(): void
    {
        $instance = $this->task(3);
        $this->tickUnit($instance, 0);

        $this->edit($instance, ['checks_per_unit' => false])
            ->assertSessionHasErrors(['checks_per_unit' => 'Er zijn al checks afgevinkt; dit kan niet meer wijzigen.']);

        $this->assertTrue($instance->fresh()->checks_per_unit);
    }

    public function test_switching_before_any_tick_lays_the_checks_out_again(): void
    {
        $instance = $this->task(3);

        $this->edit($instance, ['checks_per_unit' => false])->assertSessionHasNoErrors();

        $this->assertSame([null], $this->units($instance));
        $this->assertSame(1, $instance->checks()->count());
    }

    public function test_removing_an_extra_removes_it_from_every_unit(): void
    {
        $instance = $this->task(2, true, new PickedExtras(titles: ['Foto gemaakt']));

        $this->edit($instance, [
            'extra_check_ids' => [],
            'extra_checklist_ids' => [],
            'extra_titles' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $instance->checks()->where('title', 'Foto gemaakt')->count());
        $this->assertSame(2, $instance->checks()->where('title', 'Rooster schoongemaakt')->count());
    }

    public function test_adding_an_extra_adds_it_to_every_unit(): void
    {
        $instance = $this->task(2);

        $this->edit($instance, ['extra_titles' => ['Foto gemaakt']])->assertSessionHasNoErrors();

        $this->assertSame(2, $instance->checks()->where('title', 'Foto gemaakt')->where('is_extra', true)->count());
    }

    private function taskWithExtraChecklist(): array
    {
        $list = ServiceOrderTaskChecklist::create(['title' => 'Checklist oplevering']);
        $photo = ServiceOrderTaskCheck::create(['title' => 'Foto gemaakt']);
        $list->checks()->attach($photo->id, ['is_required' => true, 'sort_order' => 1]);

        $instance = $this->task(2, true, new PickedExtras(checklist_ids: [$list->id]));
        $instance->checks()->where('title', 'Foto gemaakt')->update(['is_done' => true]);

        return [$instance, $list, $photo];
    }

    private function sameExtras(ServiceOrderTaskChecklist $list): array
    {
        return ['extra_check_ids' => [], 'extra_checklist_ids' => [$list->id], 'extra_titles' => []];
    }

    public function test_editing_keeps_an_extra_checklist_as_it_was_copied(): void
    {
        [$instance, $list, $photo] = $this->taskWithExtraChecklist();
        $list->checks()->detach($photo->id);
        $list->checks()->attach(ServiceOrderTaskCheck::create(['title' => 'Nieuw in de lijst'])->id, [
            'is_required' => true,
            'sort_order' => 2,
        ]);

        $this->edit($instance, ['title' => 'Andere titel'] + $this->sameExtras($list))->assertSessionHasNoErrors();

        $this->assertSame(2, $instance->checks()->where('title', 'Foto gemaakt')->where('is_done', true)->count());
        $this->assertSame(0, $instance->checks()->where('title', 'Nieuw in de lijst')->count());
    }

    public function test_a_completed_task_with_an_extra_checklist_can_be_renamed(): void
    {
        [$instance, $list, $photo] = $this->taskWithExtraChecklist();
        $instance->update(['is_complete' => true]);
        $list->checks()->detach($photo->id);

        $this->edit($instance, ['title' => 'Andere titel'] + $this->sameExtras($list))->assertSessionHasNoErrors();

        $this->assertSame('Andere titel', $instance->fresh()->title);
    }

    public function test_the_edit_form_cannot_complete_a_task(): void
    {
        $instance = $this->task(1, false);

        $this->edit($instance, ['is_complete' => true]);

        $this->assertFalse($instance->fresh()->is_complete);
    }

    private ?Product $part = null;

    private ?Product $bundle_product = null;

    private function bundleTask(int $quantity): ServiceOrderTaskInstance
    {
        $template = ServiceOrderTask::create(['title' => 'Zonnepanelen']);
        $template->checks()->attach(ServiceOrderTaskCheck::create(['title' => 'Kabels gecontroleerd'])->id, [
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $bundle = $this->bundle_product = Product::factory()->create(['bundle' => true]);
        $this->part = Product::factory()->create(['bundle' => false, 'registable' => true]);
        Productable::create([
            'product_id' => $bundle->id,
            'productable_type' => Product::class,
            'productable_id' => $this->part->id,
            'quantity' => 1,
        ]);

        return app(CreateTaskInstanceAction::class)->execute([
            'service_order_id' => ServiceOrder::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'service_order_task_id' => $template->id,
            'product_id' => $bundle->id,
            'quantity' => $quantity,
            'checks_per_unit' => true,
        ]);
    }

    public function test_a_bundle_task_can_drop_bundles_that_hold_no_parts(): void
    {
        $instance = $this->bundleTask(3);
        app(RegisterTaskAssetsAction::class)->execute($instance, [[
            'product_id' => $this->part->id,
            'serial_number' => 'PANEEL-1',
            'container_index' => 0,
        ]]);

        $this->edit($instance, ['quantity' => 2])->assertSessionHasNoErrors();

        $this->assertSame([0, 1], $this->units($instance));
    }

    public function test_a_bundle_task_keeps_a_ticked_last_unit(): void
    {
        $instance = $this->bundleTask(3);
        $this->tickUnit($instance, 2);

        $this->edit($instance, ['quantity' => 2])
            ->assertSessionHasErrors(['quantity' => 'Bij machine 3 zijn al checks afgevinkt; kies een hoger aantal.']);

        $this->assertSame([0, 1, 2], $this->units($instance));
    }

    public function test_a_completed_task_cannot_change_its_aantal(): void
    {
        $instance = $this->task(2);
        $instance->checks()->update(['is_done' => true]);
        $instance->update(['is_complete' => true]);

        $this->edit($instance, ['quantity' => 3])
            ->assertSessionHasErrors(['quantity' => 'Deze taak is al voltooid; heropen hem om het aantal te wijzigen.']);

        $this->assertSame([0, 1], $this->units($instance));
    }

    public function test_switching_to_a_bundle_drops_units_from_the_end(): void
    {
        $instance = $this->task(3);
        $this->tickUnit($instance, 2);
        $this->bundleTask(1);

        $this->edit($instance, ['product_id' => $this->bundle_product->id, 'quantity' => 2])
            ->assertSessionHasErrors(['quantity' => 'Bij machine 3 zijn al checks afgevinkt; kies een hoger aantal.']);

        $this->assertSame([0, 1, 2], $this->units($instance));
    }

    public function test_removing_a_ticked_extra_is_refused(): void
    {
        $instance = $this->task(2, true, new PickedExtras(titles: ['Foto gemaakt']));
        $instance->checks()->where('title', 'Foto gemaakt')->where('unit_index', 1)->update(['is_done' => true]);

        $this->edit($instance, ['extra_check_ids' => [], 'extra_checklist_ids' => [], 'extra_titles' => []])
            ->assertSessionHasErrors(['extra_check_ids' => 'Een extra check die al is afgevinkt kan niet weg.']);

        $this->assertSame(2, $instance->checks()->where('title', 'Foto gemaakt')->count());
    }
}
