<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A contract template is the blueprint a new contract starts from. What it holds,
 * who may touch it, and that a contract can actually be created carrying what the
 * template says — the part the drawer relies on.
 */
class MaintenanceContractTemplateTest extends TestCase
{
    use RefreshDatabase;

    private int $roles_made = 0;

    private function userWith(array $permission_names): User
    {
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'role-' . ++$this->roles_made]);

        foreach ($permission_names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_the_permissions_are_seeded(): void
    {
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $this->assertDatabaseHas('permissions', ['name' => 'maintenancecontracttemplate.' . $action]);
        }
    }

    public function test_a_template_holds_everything_a_contract_has_except_customer_and_machines(): void
    {
        $user = $this->userWith(['maintenancecontracttemplate.create', 'maintenancecontracttemplate.read']);

        $this->actingAs($user)->post('/maintenancecontracttemplates', [
            'name' => 'Standaard jaarcontract',
            'title' => 'Onderhoud {jaar} — {klant}',
            'duration_months' => 12,
            'price' => 450,
            'price_interval' => 'Jaarlijks',
            'manage_frequency_per_asset' => false,
            'frequency' => 'Halfjaarlijks',
            'auto_generate' => true,
            'auto_generate_interval' => 'Jaarlijks',
        ])->assertSessionHasNoErrors();

        $template = MaintenanceContractTemplate::sole();

        $this->assertSame('Onderhoud {jaar} — {klant}', $template->title);
        $this->assertSame(12, $template->duration_months);
        $this->assertSame('450.00', $template->price);
        $this->assertSame('Jaarlijks', $template->price_interval->value);
        $this->assertSame('Halfjaarlijks', $template->frequency->value);
        $this->assertTrue($template->auto_generate);
        $this->assertSame('Jaarlijks', $template->auto_generate_interval->value);
    }

    public function test_the_pages_open_for_who_may_see_them(): void
    {
        $template = MaintenanceContractTemplate::create(['name' => 'Standaard']);
        $reader = $this->userWith(['maintenancecontracttemplate.read']);

        $this->actingAs($reader)->get('/maintenancecontracttemplates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MaintenanceContractTemplates/IndexPage')
                ->has('templates', 1)
                ->has('contractIntervalOptions'));

        $this->actingAs($reader)->get('/maintenancecontracttemplates/' . $template->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MaintenanceContractTemplates/ShowPage')
                ->where('template.name', 'Standaard'));
    }

    /**
     * The show page saves one field per edit, so an interval and its day count never
     * arrive together — the day count has to be accepted on its own.
     */
    public function test_a_single_field_can_be_saved_the_way_the_show_page_does(): void
    {
        $template = MaintenanceContractTemplate::create([
            'name' => 'Los veld',
            'frequency' => 'Aangepast (dagen)',
        ]);

        $this->actingAs($this->userWith(['maintenancecontracttemplate.update']))
            ->patch('/maintenancecontracttemplates/' . $template->id, ['frequency_days' => 45])
            ->assertSessionHasNoErrors();

        $this->assertSame(45, $template->refresh()->frequency_days);
    }

    public function test_a_template_may_leave_everything_but_its_name_open(): void
    {
        $user = $this->userWith(['maintenancecontracttemplate.create']);

        $this->actingAs($user)
            ->post('/maintenancecontracttemplates', ['name' => 'Kaal sjabloon'])
            ->assertSessionHasNoErrors();

        $template = MaintenanceContractTemplate::sole();

        $this->assertNull($template->price);
        $this->assertNull($template->frequency);
        $this->assertNull($template->duration_months);
        $this->assertFalse($template->auto_generate);
    }

    public function test_a_template_without_a_name_is_rejected(): void
    {
        $this->actingAs($this->userWith(['maintenancecontracttemplate.create']))
            ->post('/maintenancecontracttemplates', ['price' => 100])
            ->assertSessionHasErrors('name');
    }

    public function test_two_templates_cannot_share_a_name_but_one_may_keep_its_own(): void
    {
        $user = $this->userWith(['maintenancecontracttemplate.create', 'maintenancecontracttemplate.update']);
        MaintenanceContractTemplate::create(['name' => 'Bestaand']);
        $other = MaintenanceContractTemplate::create(['name' => 'Ander']);

        $this->actingAs($user)
            ->post('/maintenancecontracttemplates', ['name' => 'Bestaand'])
            ->assertSessionHasErrors('name');

        $this->actingAs($user)
            ->patch('/maintenancecontracttemplates/' . $other->id, ['name' => 'Ander', 'duration_months' => 6])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, $other->refresh()->duration_months);
    }

    public function test_an_interval_outside_the_enum_is_rejected(): void
    {
        $this->actingAs($this->userWith(['maintenancecontracttemplate.create']))
            ->post('/maintenancecontracttemplates', ['name' => 'Fout interval', 'frequency' => 'Wekelijks'])
            ->assertSessionHasErrors('frequency');
    }

    public function test_a_custom_interval_needs_its_day_count(): void
    {
        $this->actingAs($this->userWith(['maintenancecontracttemplate.create']))
            ->post('/maintenancecontracttemplates', [
                'name' => 'Aangepast zonder dagen',
                'frequency' => 'Aangepast (dagen)',
            ])
            ->assertSessionHasErrors('frequency_days');
    }

    public function test_a_template_can_be_deleted(): void
    {
        $template = MaintenanceContractTemplate::create(['name' => 'Weg hiermee']);

        $this->actingAs($this->userWith(['maintenancecontracttemplate.delete']))
            ->delete('/maintenancecontracttemplates/' . $template->id)
            ->assertRedirect();

        $this->assertDatabaseCount('maintenance_contract_templates', 0);
    }

    /**
     * Unauthorized requests are turned into a redirect with an error message
     * app-wide, so that — and not a 403 — is what a missing right looks like.
     */
    public function test_the_pages_and_the_writes_each_need_their_own_right(): void
    {
        $template = MaintenanceContractTemplate::create(['name' => 'Afgeschermd']);
        $outsider = $this->userWith(['maintenancecontract.read', 'maintenancecontract.create']);

        $this->actingAs($outsider)->get('/maintenancecontracttemplates')->assertSessionHas('error');
        $this->actingAs($outsider)->get('/maintenancecontracttemplates/' . $template->id)->assertSessionHas('error');
        $this->actingAs($outsider)->post('/maintenancecontracttemplates', ['name' => 'Mag niet'])
            ->assertSessionHas('error');
        $this->actingAs($outsider)->patch('/maintenancecontracttemplates/' . $template->id, ['name' => 'Mag niet'])
            ->assertSessionHas('error');
        $this->actingAs($outsider)->delete('/maintenancecontracttemplates/' . $template->id)
            ->assertSessionHas('error');

        $this->assertDatabaseCount('maintenance_contract_templates', 1);
        $this->assertSame('Afgeschermd', MaintenanceContractTemplate::sole()->name);
    }

    public function test_the_templates_travel_to_the_contract_pages_only_for_who_may_see_them(): void
    {
        MaintenanceContractTemplate::create(['name' => 'Standaard']);

        $this->actingAs($this->userWith(['maintenancecontract.read']))
            ->get('/maintenancecontracts')
            ->assertInertia(fn ($page) => $page->where('contractTemplates', []));

        $this->actingAs($this->userWith(['maintenancecontract.read', 'maintenancecontracttemplate.read']))
            ->get('/maintenancecontracts')
            ->assertInertia(fn ($page) => $page->has('contractTemplates', 1));
    }

    /**
     * The drawer sends what the template filled in. Auto-generation is the part a
     * contract could not be created with before templates existed, so it is the
     * part that has to keep working.
     */
    public function test_a_contract_is_created_with_the_auto_generation_a_template_carries(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->userWith(['maintenancecontract.create']))->post('/maintenancecontracts', [
            'customer_id' => $customer->id,
            'title' => 'Onderhoud 2026 — ' . $customer->name,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'price' => 450,
            'price_interval' => 'Jaarlijks',
            'manage_frequency_per_asset' => false,
            'frequency' => 'Halfjaarlijks',
            'auto_generate' => true,
            'auto_generate_interval' => 'Jaarlijks',
        ])->assertSessionHasNoErrors();

        $contract = MaintenanceContract::sole();

        $this->assertTrue($contract->auto_generate);
        $this->assertSame('Jaarlijks', $contract->auto_generate_interval->value);
        $this->assertSame('450.00', $contract->price);
        $this->assertTrue(
            $contract->activities()->get()->contains(
                fn ($activity) => str_contains((string) $activity->description, 'Automatisch genereren')
            ),
            'De tijdlijn hoort te melden dat het contract zelf werkbonnen gaat maken.'
        );
    }
}
