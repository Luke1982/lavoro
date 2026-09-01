<?php

namespace Tests\Feature;

use App\Http\Requests\UserStoreRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * De rol van MajorLabel binnen de database van een klant. Mag alles, en een
 * klant mag er op geen enkele manier bij: niet zien, niet aanmaken, niet
 * toekennen. Elke weg daarheen krijgt hier zijn eigen test, want één open
 * deur is genoeg.
 */
class SuperAdminRoleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => Role::SUPERADMIN])->id);

        return $user->fresh();
    }

    public function test_a_super_admin_passes_every_permission_check(): void
    {
        $super = $this->superAdmin();

        $this->assertTrue($super->isSuperAdmin());
        $this->assertTrue($super->isAdmin());
        $this->assertTrue($super->hasPermission('een.recht.dat.niet.bestaat'));
        $this->assertTrue($super->can('viewAny', User::class));
    }

    public function test_an_ordinary_admin_is_not_a_super_admin(): void
    {
        $admin = $this->admin();

        $this->assertFalse($admin->isSuperAdmin());
        $this->assertTrue($admin->isAdmin());
    }

    public function test_the_role_is_not_offered_to_a_customer(): void
    {
        Role::firstOrCreate(['name' => Role::SUPERADMIN]);

        $this->assertNotContains(Role::SUPERADMIN, Role::assignable()->pluck('name')->all());
        $this->assertContains(Role::SUPERADMIN, Role::query()->pluck('name')->all());
    }

    public function test_a_customer_cannot_create_a_role_by_that_name(): void
    {
        $response = $this->actingAs($this->admin())->post('/roles', ['name' => Role::SUPERADMIN]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Role::where('name', Role::SUPERADMIN)->count());
    }

    public function test_a_customer_cannot_assign_the_role_through_the_user_form(): void
    {
        $role = Role::firstOrCreate(['name' => Role::SUPERADMIN]);
        $admin = $this->userWithPermissions('user.assign_roles');

        $request = new UserStoreRequest;
        $request->setUserResolver(fn () => $admin);
        $rules = $request->rules();

        $this->assertArrayHasKey('role_ids.*', $rules, 'Deze gebruiker hoort rollen te mogen toekennen.');

        $refused = Validator::make(['role_ids' => [$role->id]], [
            'role_ids' => $rules['role_ids'],
            'role_ids.*' => $rules['role_ids.*'],
        ]);

        $this->assertTrue($refused->fails(), 'De rol van MajorLabel mag niet toe te kennen zijn.');

        $ordinary = Role::firstOrCreate(['name' => 'Monteur']);

        $allowed = Validator::make(['role_ids' => [$ordinary->id]], [
            'role_ids' => $rules['role_ids'],
            'role_ids.*' => $rules['role_ids.*'],
        ]);

        $this->assertFalse($allowed->fails(), 'Een gewone rol hoort gewoon toegekend te kunnen worden.');
    }

    public function test_a_customer_cannot_fill_the_role_with_users(): void
    {
        $role = Role::firstOrCreate(['name' => Role::SUPERADMIN]);
        $victim = User::factory()->create();

        $this->actingAs($this->admin())
            ->put('/roles/' . $role->id, ['user_ids' => [$victim->id]])
            ->assertNotFound();

        $this->assertSame(0, $role->users()->count());
    }

    public function test_technical_management_is_for_the_super_admin_alone(): void
    {
        /**
         * Zonder dit maakt de afhandelaar er een 302 met een melding van, en
         * dan zegt de test alleen dat er iets gebeurde. Zo staat er waarom hij
         * geweigerd wordt.
         */
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin())->get('/technical-management');
            $this->fail('Een gewone beheerder hoort niet bij Technisch beheer te kunnen.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->superAdmin())->get('/technical-management')->assertOk();
    }

    public function test_a_customer_never_sees_the_account_in_any_list(): void
    {
        $super = $this->superAdmin();
        $admin = $this->admin();

        $this->actingAs($admin);

        $this->assertFalse(
            User::where('id', $super->id)->exists(),
            'De globale scope hoort ons account overal uit te houden, niet alleen uit het gebruikersscherm.',
        );

        $this->actingAs($super);

        $this->assertTrue(
            User::where('id', $super->id)->exists(),
            'Een superbeheerder hoort zichzelf wel te zien.',
        );
    }

    public function test_logging_in_still_finds_the_account(): void
    {
        $super = $this->superAdmin();

        /** Zonder ingelogde gebruiker doet de scope niets, anders kan hij er nooit meer in. */
        $this->assertTrue(User::where('id', $super->id)->exists());
    }

    public function test_a_customer_cannot_edit_or_delete_the_account(): void
    {
        $super = $this->superAdmin();
        $admin = $this->admin();

        $this->assertFalse($admin->can('view', $super));
        $this->assertFalse($admin->can('update', $super));
        $this->assertFalse($admin->can('delete', $super));

        /** En wij kunnen er zelf wel bij. */
        $this->assertTrue($super->can('update', $super));
    }

    public function test_the_account_does_not_occupy_a_seat(): void
    {
        $before = User::occupyingSeat('office')->count();

        $this->superAdmin()->update(['seat_type' => 'office']);

        $this->assertSame(
            $before,
            User::occupyingSeat('office')->count(),
            'Ons eigen account hoort geen plaats uit het abonnement van de klant te kosten.',
        );
    }

    public function test_the_old_technical_management_role_is_gone(): void
    {
        $this->assertSame(0, Role::where('name', 'technisch beheer')->count());
        $this->assertSame(
            0,
            Permission::where('name', 'technical.management')->count(),
            'Het losse recht is vervangen door de superbeheerder.',
        );
    }
}
