<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

class ProjectFinancialNotesStateTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function accountant(string $name = 'Boekhouder'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $role = Role::firstOrCreate(['name' => 'boekhouder']);
        foreach (['project.read', 'project.manage_financials'] as $permission_name) {
            $permission = Permission::firstOrCreate(['name' => $permission_name], ['label' => $permission_name]);
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
        return ['data' => [['a']], 'style' => [], 'mergeCells' => [], 'columns' => []];
    }

    private function save(User $user, Project $project)
    {
        return $this->actingAs($user, 'sanctum')->patchJson(
            '/api/projects/' . $project->id . '/financial-notes',
            ['financial_notes' => $this->snapshot()]
        );
    }

    private function state(User $user, Project $project)
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/projects/' . $project->id . '/financial-notes/state');
    }

    public function test_state_is_empty_before_anything_is_saved(): void
    {
        $project = $this->project();

        $this->state($this->accountant(), $project)
            ->assertOk()
            ->assertJson(['saved_at' => null, 'saved_by' => null]);
    }

    public function test_saving_records_who_saved_and_when(): void
    {
        $project = $this->project();
        $user = $this->accountant('Jan Bakker');

        $response = $this->save($user, $project)->assertOk();

        $this->assertNotNull($response->json('saved_at'));
        $this->assertSame('Jan Bakker', $response->json('saved_by.name'));

        $this->state($user, $project)
            ->assertOk()
            ->assertJson([
                'saved_at' => $response->json('saved_at'),
                'saved_by' => ['id' => $user->id, 'name' => 'Jan Bakker'],
            ]);
    }

    public function test_a_second_editor_sees_a_newer_timestamp_than_their_own(): void
    {
        $project = $this->project();
        $first = $this->accountant('Jan Bakker');
        $second = $this->accountant('Piet Jansen');

        $first_saved_at = $this->save($first, $project)->json('saved_at');

        $this->travel(5)->seconds();

        $second_saved_at = $this->save($second, $project)->json('saved_at');

        $this->assertNotSame($first_saved_at, $second_saved_at);

        $this->state($first, $project)
            ->assertJson(['saved_at' => $second_saved_at, 'saved_by' => ['name' => 'Piet Jansen']]);
    }

    public function test_state_requires_the_manage_financials_permission(): void
    {
        $project = $this->project();

        $this->state($this->userWith('project.read'), $project)->assertForbidden();
    }

    public function test_state_is_not_reachable_by_guests(): void
    {
        $project = $this->project();

        $this->getJson('/api/projects/' . $project->id . '/financial-notes/state')
            ->assertUnauthorized();
    }

    public function test_the_project_page_exposes_the_stamp_to_permitted_users_only(): void
    {
        $project = $this->project();
        $this->save($this->accountant('Jan Bakker'), $project);

        $permitted = $this->actingAs($this->accountant())->get('/projects/' . $project->id);
        $this->assertArrayHasKey(
            'financial_notes_updated_at',
            $permitted->viewData('page')['props']['project']
        );

        $unpermitted = $this->actingAs($this->userWith('project.read'))->get('/projects/' . $project->id);
        $this->assertArrayNotHasKey(
            'financial_notes_updated_at',
            $unpermitted->viewData('page')['props']['project']
        );
    }
}
