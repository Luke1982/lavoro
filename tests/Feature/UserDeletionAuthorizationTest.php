<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeletionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function givePermission(User $user, string $permission_name): void
    {
        $role = Role::firstOrCreate(['name' => $permission_name . '-role']);
        $permission = Permission::firstOrCreate(['name' => $permission_name], ['label' => $permission_name]);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->roles()->attach($role->id);
    }

    public function test_user_without_permission_cannot_delete_a_user(): void
    {
        /** @var User $actor */
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($actor)->delete(route('users.destroy', $target));

        $response->assertSessionHas('error');
        $this->assertNull($target->fresh()->deleted_at);
    }

    public function test_user_with_permission_can_delete_another_user(): void
    {
        /** @var User $actor */
        $actor = User::factory()->create();
        $this->givePermission($actor, 'user.delete');
        $target = User::factory()->create();

        $response = $this->actingAs($actor)->delete(route('users.destroy', $target));

        $response->assertRedirect(route('users.index'));
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_user_cannot_delete_themselves(): void
    {
        /** @var User $actor */
        $actor = User::factory()->create();
        $this->givePermission($actor, 'user.delete');

        $response = $this->actingAs($actor)->delete(route('users.destroy', $actor));

        $response->assertSessionHas('error');
        $this->assertNull($actor->fresh()->deleted_at);
    }

    public function test_deleting_does_not_grant_restore_permission(): void
    {
        /** @var User $actor */
        $actor = User::factory()->create();
        $this->givePermission($actor, 'user.delete');
        $target = User::factory()->create();
        $target->delete();

        $response = $this->actingAs($actor)->post(route('users.restore', $target));

        $response->assertSessionHas('error');
        $this->assertNotNull($target->fresh()->deleted_at);
    }

    public function test_user_with_restore_permission_can_restore(): void
    {
        /** @var User $actor */
        $actor = User::factory()->create();
        $this->givePermission($actor, 'user.restore');
        $target = User::factory()->create();
        $target->delete();

        $response = $this->actingAs($actor)->post(route('users.restore', $target));

        $response->assertRedirect(route('users.index'));
        $this->assertNull($target->fresh()->deleted_at);
    }

    public function test_deleted_users_are_only_exposed_to_privileged_viewers(): void
    {
        /** @var User $unprivileged */
        $unprivileged = User::factory()->create();
        $this->givePermission($unprivileged, 'user.read');
        /** @var User $privileged */
        $privileged = User::factory()->create();
        $this->givePermission($privileged, 'user.read');
        $this->givePermission($privileged, 'user.delete');
        User::factory()->create()->delete();

        $unprivilegedResponse = $this->actingAs($unprivileged)->get(route('users.index'));
        $this->assertCount(0, $unprivilegedResponse->inertiaProps('deletedUsers'));

        $privilegedResponse = $this->actingAs($privileged)->get(route('users.index'));
        $this->assertCount(1, $privilegedResponse->inertiaProps('deletedUsers'));
    }
}
