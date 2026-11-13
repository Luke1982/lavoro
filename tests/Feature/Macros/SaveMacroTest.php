<?php

namespace Tests\Feature\Macros;

use App\Models\ServiceOrderMacro;
use App\Models\ServiceOrderTask;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A macro is checked when it is saved: every block must be a known kind with
 * valid settings, every block needs an id of its own, and a setting left to
 * "vragen bij uitvoeren" needs its question.
 */
class SaveMacroTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function save(array $steps, ?array $permissions = null)
    {
        return $this->actingAs($this->userWithPermissions(...($permissions ?? ['serviceordermacro.manage'])))
            ->post('/serviceordermacros', ['title' => 'Airco met offerte', 'definition' => ['steps' => $steps]]);
    }

    public function test_a_valid_macro_is_saved(): void
    {
        $task = ServiceOrderTask::create(['title' => 'Airco installatie']);

        $this->save([
            ['id' => 'a', 'type' => 'add_task', 'service_order_task_id' => $task->id, 'quantity' => ['ask' => "Hoeveel airco's?"]],
            ['id' => 'b', 'type' => 'question', 'question' => 'Is er een offerte?', 'yes' => [], 'no' => [
                ['id' => 'c', 'type' => 'add_remark', 'text' => 'Offerte volgt', 'internal' => true],
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Airco met offerte', ServiceOrderMacro::sole()->title);
        $this->assertCount(2, ServiceOrderMacro::sole()->definition['steps']);
    }

    public function test_an_unknown_block_is_refused(): void
    {
        $this->save([['id' => 'a', 'type' => 'launch_rocket']])
            ->assertSessionHasErrors(['definition.steps.0.type' => 'Onbekende stap.']);

        $this->assertSame(0, ServiceOrderMacro::count());
    }

    public function test_blocks_need_ids_of_their_own(): void
    {
        $this->save([
            ['id' => 'a', 'type' => 'add_remark', 'text' => 'Een', 'internal' => false],
            ['id' => 'a', 'type' => 'add_remark', 'text' => 'Twee', 'internal' => false],
        ])->assertSessionHasErrors('definition.steps.1.id');
    }

    public function test_a_block_id_holds_no_dots(): void
    {
        $this->save([['id' => 'a.b', 'type' => 'add_remark', 'text' => 'Een', 'internal' => false]])
            ->assertSessionHasErrors('definition.steps.0.id');
    }

    public function test_a_malformed_definition_is_refused(): void
    {
        $this->save([null])->assertSessionHasErrors('definition.steps.0');
        $this->save([['id' => 'a', 'type' => ['add_remark']]])->assertSessionHasErrors('definition.steps.0.type');
        $this->save([['id' => 'a', 'type' => 'question', 'question' => 'Offerte?', 'yes' => ['x'], 'no' => 'y']])
            ->assertSessionHasErrors(['definition.steps.0.yes.0', 'definition.steps.0.no']);

        $this->assertSame(0, ServiceOrderMacro::count());
    }

    public function test_block_errors_come_with_a_missing_name(): void
    {
        $this->actingAs($this->userWithPermissions('serviceordermacro.manage'))
            ->post('/serviceordermacros', ['title' => '', 'definition' => ['steps' => [
                ['id' => 'a', 'type' => 'add_remark', 'internal' => false],
            ]]])
            ->assertSessionHasErrors(['title', 'definition.steps.0.text']);
    }

    public function test_a_block_inside_a_lane_is_checked_too(): void
    {
        $this->save([
            ['id' => 'a', 'type' => 'question', 'question' => 'Offerte?', 'yes' => [
                ['id' => 'b', 'type' => 'add_remark', 'internal' => false],
            ], 'no' => []],
        ])->assertSessionHasErrors('definition.steps.0.yes.0.text');
    }

    public function test_an_asked_setting_needs_its_question(): void
    {
        $this->save([['id' => 'a', 'type' => 'add_remark', 'text' => ['ask' => ''], 'internal' => false]])
            ->assertSessionHasErrors('definition.steps.0.text.ask');
    }

    public function test_building_macros_needs_its_own_right(): void
    {
        $this->save([['id' => 'a', 'type' => 'add_remark', 'text' => 'Een', 'internal' => false]], ['serviceorder.update'])
            ->assertSessionHas('error');

        $this->assertSame(0, ServiceOrderMacro::count());
    }
}
