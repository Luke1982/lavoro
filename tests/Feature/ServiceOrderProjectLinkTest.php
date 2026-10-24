<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderProjectLinkTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'werkbon-manager']);
        $permission = Permission::firstOrCreate(
            ['name' => 'serviceorder.update'],
            ['label' => 'serviceorder.update']
        );
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $this->user = $user;
    }

    private function project(): Project
    {
        return Project::create([
            'title' => 'Testproject',
            'customer_id' => $this->customer->id,
            'project_manager_id' => $this->user->id,
            'status' => 'Niet gestart',
        ]);
    }

    public function test_linking_a_project_is_saved(): void
    {
        $project = $this->project();
        $order = ServiceOrder::factory()->create(['customer_id' => $this->customer->id, 'project_id' => null]);

        $response = $this->actingAs($this->user)->put(route('serviceorders.update', $order), [
            'customer_id' => $this->customer->id,
            'project_id' => $project->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($project->id, $order->refresh()->project_id);
    }

    public function test_unlinking_a_project_is_saved(): void
    {
        $project = $this->project();
        $order = ServiceOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'project_id' => $project->id,
        ]);

        $response = $this->actingAs($this->user)->put(route('serviceorders.update', $order), [
            'customer_id' => $this->customer->id,
            'project_id' => null,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull($order->refresh()->project_id);
    }

    public function test_a_non_existing_project_is_rejected(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => $this->customer->id, 'project_id' => null]);

        $response = $this->actingAs($this->user)->put(route('serviceorders.update', $order), [
            'customer_id' => $this->customer->id,
            'project_id' => 999999,
        ]);

        $response->assertSessionHasErrors('project_id');
        $this->assertNull($order->refresh()->project_id);
    }
}
