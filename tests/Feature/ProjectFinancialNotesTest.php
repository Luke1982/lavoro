<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class ProjectFinancialNotesTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function accountant(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'boekhouder']);
        foreach (['project.read', 'project.manage_financials'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function project(): Project
    {
        return Project::create([
            'title' => 'Testproject',
            'customer_id' => Customer::factory()->create()->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'new',
        ]);
    }

    private function snapshot(): array
    {
        return [
            'data' => [['Post', 'Bedrag'], ['Onderaanneming', 4500], ['Marge', 12.5], ['Leeg', null]],
            'style' => ['A1' => 'background-color: cyan; font-weight: bold;'],
            'mergeCells' => ['C1' => [2, 1]],
            'columns' => [['width' => 220], ['title' => 'Bedrag']],
        ];
    }

    private function save(User $user, Project $project, $financial_notes)
    {
        return $this->actingAs($user, 'sanctum')->patchJson(
            '/api/projects/' . $project->id . '/financial-notes',
            ['financial_notes' => $financial_notes]
        );
    }

    public function test_permitted_user_sees_financial_notes_on_the_project_page(): void
    {
        $project = $this->project();
        $project->update(['financial_notes' => $this->snapshot()]);

        $response = $this->actingAs($this->accountant())->get('/projects/' . $project->id);

        $response->assertOk();
        $this->assertArrayHasKey('financial_notes', $response->viewData('page')['props']['project']);
    }

    public function test_financial_notes_are_hidden_from_users_without_the_permission(): void
    {
        $project = $this->project();
        $project->update(['financial_notes' => $this->snapshot()]);

        $response = $this->actingAs($this->userWith('project.read'))->get('/projects/' . $project->id);

        $response->assertOk();
        $this->assertArrayNotHasKey('financial_notes', $response->viewData('page')['props']['project']);
    }

    public function test_guest_cannot_reach_the_endpoint(): void
    {
        $project = $this->project();

        $this->patchJson('/api/projects/' . $project->id . '/financial-notes', ['financial_notes' => null])
            ->assertUnauthorized();
    }

    public function test_user_without_the_permission_cannot_save(): void
    {
        $project = $this->project();
        $project->update(['financial_notes' => $this->snapshot()]);

        $payload = ['data' => [['gehackt']], 'style' => [], 'mergeCells' => [], 'columns' => []];

        $this->save($this->userWith('project.read'), $project, $payload)->assertForbidden();

        $this->assertEquals($this->snapshot(), $project->fresh()->financial_notes);
    }

    public function test_a_complete_snapshot_round_trips_exactly(): void
    {
        $project = $this->project();

        $this->save($this->accountant(), $project, $this->snapshot())
            ->assertOk()
            ->assertJsonStructure(['saved_at']);

        $this->assertEquals($this->snapshot(), $project->fresh()->financial_notes);
    }

    public function test_numeric_and_null_cells_survive_unchanged(): void
    {
        $project = $this->project();
        $snapshot = $this->snapshot();

        $this->save($this->accountant(), $project, $snapshot)->assertOk();

        $stored = $project->fresh()->financial_notes;
        $this->assertSame(4500, $stored['data'][1][1]);
        $this->assertSame(12.5, $stored['data'][2][1]);
        $this->assertNull($stored['data'][3][1]);
    }

    public function test_an_unstyled_sheet_may_send_empty_maps(): void
    {
        $project = $this->project();
        $plain = ['data' => [['a', 'b']], 'style' => [], 'mergeCells' => [], 'columns' => []];

        $this->save($this->accountant(), $project, $plain)->assertOk();

        $this->assertEquals($plain, $project->fresh()->financial_notes);
    }

    public function test_null_clears_the_column(): void
    {
        $project = $this->project();
        $project->update(['financial_notes' => $this->snapshot()]);

        $this->save($this->accountant(), $project, null)->assertOk();

        $this->assertNull($project->fresh()->financial_notes);
    }

    public function test_a_legacy_bare_array_is_normalised_rather_than_discarded(): void
    {
        $project = $this->project();
        $legacy = [['Oud', 'formaat'], ['x', 1]];

        $this->save($this->accountant(), $project, $legacy)->assertOk();

        $this->assertEquals(
            ['data' => $legacy, 'style' => [], 'mergeCells' => [], 'columns' => []],
            $project->fresh()->financial_notes
        );
    }

    public static function partialSnapshots(): array
    {
        return [
            'missing data' => [['style' => ['A1' => 'color: red;'], 'mergeCells' => [], 'columns' => []]],
            'missing style' => [['data' => [['x']], 'mergeCells' => [], 'columns' => []]],
            'missing mergeCells' => [['data' => [['x']], 'style' => [], 'columns' => []]],
            'missing columns' => [['data' => [['x']], 'style' => [], 'mergeCells' => []]],
            'only style' => [['style' => ['A1' => 'color: red;']]],
        ];
    }

    #[DataProvider('partialSnapshots')]
    public function test_partial_snapshots_are_rejected_and_leave_the_grid_intact(array $payload): void
    {
        $project = $this->project();
        $project->update(['financial_notes' => $this->snapshot()]);

        $this->save($this->accountant(), $project, $payload)->assertStatus(422);

        $this->assertEquals($this->snapshot(), $project->fresh()->financial_notes);
    }

    public function test_unknown_keys_are_not_persisted(): void
    {
        $project = $this->project();
        $payload = $this->snapshot();
        $payload['evil'] = ['arbitrary' => 'blob'];

        $this->save($this->accountant(), $project, $payload)->assertOk();

        $this->assertArrayNotHasKey('evil', $project->fresh()->financial_notes);
    }

    public function test_non_scalar_cell_values_are_rejected(): void
    {
        $project = $this->project();

        $this->save($this->accountant(), $project, [
            'data' => [[['nested' => 'object']]],
            'style' => [],
            'mergeCells' => [],
            'columns' => [],
        ])->assertStatus(422);

        $this->assertNull($project->fresh()->financial_notes);
    }

    public function test_oversized_grids_are_rejected(): void
    {
        $project = $this->project();
        $user = $this->accountant();

        $this->save($user, $project, [
            'data' => array_fill(0, 2001, ['x']),
            'style' => [], 'mergeCells' => [], 'columns' => [],
        ])->assertJsonValidationErrors('financial_notes.data');

        $this->save($user, $project, [
            'data' => [array_fill(0, 101, 'x')],
            'style' => [], 'mergeCells' => [], 'columns' => [],
        ])->assertJsonValidationErrors('financial_notes.data.0');

        $this->assertNull($project->fresh()->financial_notes);
    }

    public function test_malformed_styles_and_merges_are_rejected(): void
    {
        $project = $this->project();
        $user = $this->accountant();

        $this->save($user, $project, [
            'data' => [['x']], 'style' => ['A1' => str_repeat('x', 501)], 'mergeCells' => [], 'columns' => [],
        ])->assertJsonValidationErrors('financial_notes.style.A1');

        $this->save($user, $project, [
            'data' => [['x']], 'style' => [], 'mergeCells' => ['A1' => [2, 1, 'junk']], 'columns' => [],
        ])->assertStatus(422);

        $this->save($user, $project, [
            'data' => [['x']], 'style' => [], 'mergeCells' => ['A1' => ['nope', 1]], 'columns' => [],
        ])->assertStatus(422);

        $this->assertNull($project->fresh()->financial_notes);
    }
}
