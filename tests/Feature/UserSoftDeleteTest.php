<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_deleting_a_user_keeps_the_record_but_hides_it_from_default_queries(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::onlyTrashed()->find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_restoring_a_user_makes_them_visible_again(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $user->restore();

        $this->assertNotNull(User::find($user->id));
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_roles_are_preserved_through_a_delete_restore_cycle(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'monteur']);
        $user->roles()->attach($role->id);

        $user->delete();
        $user->restore();

        $this->assertTrue($user->fresh()->roles()->where('roles.id', $role->id)->exists());
    }

    public function test_login_is_blocked_for_a_soft_deleted_user(): void
    {
        $user = User::factory()->create([
            'email' => 'softdeleted@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->delete();

        $ok = Auth::attempt(['email' => 'softdeleted@example.com', 'password' => 'password123']);

        $this->assertFalse($ok);
    }
}
