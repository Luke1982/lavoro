<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * The 'plannable' checkbox in the planner.
 *
 * The planner leans on it: with nobody plannable there is nothing to plan. The
 * checkbox goes over /api, and that path has its own handling of tenancy and
 * authentication -- exactly where it could go wrong.
 */
class UserPlannableApiTest extends TestCase
{
    private function planner(array $permissions = ['event.see_all']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'planner-' . uniqid()]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_a_planner_can_make_a_user_plannable(): void
    {
        $monteur = User::factory()->create(['plannable' => false]);

        $this->actingAs($this->planner())
            ->patchJson("/api/users/{$monteur->id}/plannable", ['plannable' => true])
            ->assertNoContent();

        $this->assertTrue((bool) $monteur->fresh()->plannable);
    }

    public function test_a_planner_can_take_a_user_out_of_the_planning(): void
    {
        $monteur = User::factory()->create(['plannable' => true]);

        $this->actingAs($this->planner())
            ->patchJson("/api/users/{$monteur->id}/plannable", ['plannable' => false])
            ->assertNoContent();

        $this->assertFalse((bool) $monteur->fresh()->plannable);
    }

    public function test_someone_who_is_not_signed_in_cannot_change_it(): void
    {
        $monteur = User::factory()->create(['plannable' => false]);

        $this->patchJson("/api/users/{$monteur->id}/plannable", ['plannable' => true])
            ->assertUnauthorized();

        $this->assertFalse((bool) $monteur->fresh()->plannable);
    }

    public function test_someone_without_the_right_to_plan_cannot_change_it(): void
    {
        $monteur = User::factory()->create(['plannable' => false]);

        $this->actingAs($this->planner([]))
            ->patchJson("/api/users/{$monteur->id}/plannable", ['plannable' => true])
            ->assertForbidden();

        $this->assertFalse((bool) $monteur->fresh()->plannable);
    }

    public function test_the_value_has_to_be_a_boolean(): void
    {
        $monteur = User::factory()->create(['plannable' => false]);

        $this->actingAs($this->planner())
            ->patchJson("/api/users/{$monteur->id}/plannable", ['plannable' => 'misschien'])
            ->assertJsonValidationErrorFor('plannable');
    }
}
