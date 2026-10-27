<?php

namespace Tests\Feature;

use App\Http\Requests\UserUpdateRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantSuperAdmins;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Failures that were silent: the screen reported nothing, the test was green
 * and it simply did not work. Each of these could have had a check and did not,
 * so now it does.
 */
class RegressionsFromTodayTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function superAdmin(): User
    {
        $user = User::factory()->create(['seat_type' => 'office']);
        $user->roles()->attach(Role::firstOrCreate(['name' => Role::SUPERADMIN])->id);

        return $user->fresh();
    }

    /**
     * Every field the form has to fill in belongs on that form as well.
     * seat_type was required and was not there: creating was impossible because
     * of it, with an error message that fitted nowhere.
     */
    public function test_the_create_form_receives_what_it_needs_to_fill_in(): void
    {
        $response = $this->actingAs($this->userWithPermissions('user.create'))->get('/users/create');

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('seats', $props, 'Zonder de plaatsen kan het formulier de keuze niet tonen.');
        $this->assertArrayHasKey('occupiesSeat', $props);
        $this->assertTrue($props['occupiesSeat']);
    }

    /** Our own account occupies no seat, so it does not get the choice. */
    public function test_a_super_admin_is_not_asked_for_a_seat(): void
    {
        $super = $this->superAdmin();

        $props = $this->actingAs($super)
            ->get('/users/' . $super->id . '/edit')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($props['occupiesSeat']);
    }

    /**
     * The role rule refused the super admin's role, to themselves as well.
     * Their own profile could not be saved because of it.
     */
    public function test_a_super_admin_can_save_its_own_profile(): void
    {
        $super = $this->superAdmin();
        $role = Role::where('name', Role::SUPERADMIN)->firstOrFail();

        $request = new UserUpdateRequest;
        $request->setUserResolver(fn () => $super);

        $validator = Validator::make([
            'name' => $super->name,
            'email' => $super->email,
            'role_ids' => [$role->id],
        ], $request->rules());

        $this->assertFalse(
            $validator->fails(),
            'Fouten: ' . json_encode($validator->errors()->all(), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * The admin panel manages these accounts, so they have to stay visible
     * there -- the global scope hiding them from the customer also struck in
     * the panel, which runs on another guard.
     */
    public function test_the_landlord_panel_still_sees_the_accounts_it_manages(): void
    {
        $super = $this->superAdmin();
        $tenant = Tenant::on('central')->findOrFail(tenancy()->tenant->getTenantKey());

        /** Logged in as an ordinary admin: they should not see them. */
        $this->actingAs($this->admin());

        $this->assertFalse(User::where('id', $super->id)->exists());

        $found = collect(app(TenantSuperAdmins::class)->all($tenant))->pluck('email');

        $this->assertContains($super->email, $found, 'Het paneel hoort er wel bij te kunnen.');
    }

    /**
     * An empty queue looks exactly like a worker that is not running. The
     * heartbeat is the only difference, so it has to be there and has to go
     * stale.
     */
    public function test_a_worker_without_a_heartbeat_counts_as_stopped(): void
    {
        Cache::forget(WorkerHeartbeat::key('provisioning'));

        $this->assertNull(WorkerHeartbeat::beatFor('provisioning'));

        Cache::put(WorkerHeartbeat::key('provisioning'), now()->timestamp, now()->addHour());
        $this->assertNotNull(WorkerHeartbeat::beatFor('provisioning'));

        /** Older than the limit counts as stopped. */
        $stale = now()->subMinutes(WorkerHeartbeat::STALE_AFTER_MINUTES + 1)->timestamp;
        Cache::put(WorkerHeartbeat::key('provisioning'), $stale, now()->addHour());

        $this->assertTrue(
            now()->timestamp - WorkerHeartbeat::beatFor('provisioning') > WorkerHeartbeat::STALE_AFTER_MINUTES * 60,
        );
    }
}
