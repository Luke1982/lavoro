<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Tests\TestCase;

class UserSoftDeleteVisibilityTest extends TestCase
{

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

    /**
     * Het dashboard stuurt geen gebruikerslijst meer mee sinds de planner er niet
     * meer op staat, dus er is ook niets meer waar een verwijderde gebruiker uit
     * kan opduiken. Deze test bewaakt dat die lijst niet stilletjes terugkomt —
     * gebeurt dat wel, dan komt de vraag van hierboven er meteen bij.
     */
    public function test_dashboard_ships_no_user_list(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($admin)->get('/');

        $response->assertOk();
        $this->assertNull($response->inertiaProps('allUsers'));
        $this->assertNull($response->inertiaProps('plannableUsers'));
        $response->assertDontSee($deleted->email);
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
