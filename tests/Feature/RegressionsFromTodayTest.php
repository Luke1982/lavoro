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
 * Fouten die stil waren: het scherm meldde niets, de test was groen en het
 * werkte gewoon niet. Elk van deze had een controle kunnen hebben en had die
 * niet, dus die staat er nu.
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
     * Elk veld dat het formulier moet invullen hoort ook op dat formulier te
     * staan. seat_type was verplicht en stond er niet: aanmaken was daardoor
     * onmogelijk, met een foutmelding die nergens paste.
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

    /** Ons eigen account bezet geen plaats, dus het krijgt de keuze niet. */
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
     * De rolregel weigerde de rol van de superbeheerder, ook aan hemzelf. Zijn
     * eigen profiel was daardoor niet op te slaan.
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
     * Het beheerpaneel beheert deze accounts, dus daar moeten ze zichtbaar
     * blijven -- de globale scope die ze voor de klant verbergt sloeg ook toe
     * op het paneel, dat op een andere guard draait.
     */
    public function test_the_landlord_panel_still_sees_the_accounts_it_manages(): void
    {
        $super = $this->superAdmin();
        $tenant = Tenant::on('central')->findOrFail(tenancy()->tenant->getTenantKey());

        /** Ingelogd als een gewone beheerder: die hoort ze juist niet te zien. */
        $this->actingAs($this->admin());

        $this->assertFalse(User::where('id', $super->id)->exists());

        $found = collect(app(TenantSuperAdmins::class)->all($tenant))->pluck('email');

        $this->assertContains($super->email, $found, 'Het paneel hoort er wel bij te kunnen.');
    }

    /**
     * Een lege wachtrij ziet er hetzelfde uit als een worker die niet draait.
     * De hartslag is het enige verschil, dus die moet er zijn en moet verlopen.
     */
    public function test_a_worker_without_a_heartbeat_counts_as_stopped(): void
    {
        Cache::forget(WorkerHeartbeat::key('provisioning'));

        $this->assertNull(WorkerHeartbeat::beatFor('provisioning'));

        Cache::put(WorkerHeartbeat::key('provisioning'), now()->timestamp, now()->addHour());
        $this->assertNotNull(WorkerHeartbeat::beatFor('provisioning'));

        /** Ouder dan de grens telt als gestopt. */
        $stale = now()->subMinutes(WorkerHeartbeat::STALE_AFTER_MINUTES + 1)->timestamp;
        Cache::put(WorkerHeartbeat::key('provisioning'), $stale, now()->addHour());

        $this->assertTrue(
            now()->timestamp - WorkerHeartbeat::beatFor('provisioning') > WorkerHeartbeat::STALE_AFTER_MINUTES * 60,
        );
    }
}
