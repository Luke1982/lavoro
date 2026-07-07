<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class UserSoftDeleteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        /** @var User $admin */
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin->roles()->attach($role->id);

        return $admin;
    }

    private function assertPropExcludesDeletedUser(
        $response,
        string $propPath,
        int $active_user_id,
        int $deleted_user_id
    ): void {
        $ids = Arr::pluck($response->inertiaProps($propPath), 'id');

        $this->assertContains($active_user_id, $ids);
        $this->assertNotContains($deleted_user_id, $ids);
    }

    public function test_planner_excludes_soft_deleted_users(): void
    {
        $admin = $this->makeAdmin();
        $active = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($admin)->get(route('planner.index'));

        $response->assertOk();
        $this->assertPropExcludesDeletedUser($response, 'allUsers', $active->id, $deleted->id);
        $this->assertPropExcludesDeletedUser($response, 'allPlanUsers', $active->id, $deleted->id);
    }

    public function test_dashboard_excludes_soft_deleted_users(): void
    {
        $admin = $this->makeAdmin();
        $active = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($admin)->get('/');

        $response->assertOk();
        $this->assertPropExcludesDeletedUser($response, 'allUsers', $active->id, $deleted->id);
    }

    public function test_upcoming_activities_excludes_soft_deleted_users(): void
    {
        $admin = $this->makeAdmin();
        $active = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($admin)->get(route('upcomingactivities'));

        $response->assertOk();
        $this->assertPropExcludesDeletedUser($response, 'allUsers', $active->id, $deleted->id);
    }

    public function test_roles_index_excludes_soft_deleted_users(): void
    {
        $admin = $this->makeAdmin();
        $active = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($admin)->get(route('roles.index'));

        $response->assertOk();
        $this->assertPropExcludesDeletedUser($response, 'allUsers', $active->id, $deleted->id);
    }
}
