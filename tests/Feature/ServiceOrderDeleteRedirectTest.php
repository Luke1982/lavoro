<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use Tests\TestCase;

/**
 * Deleting a werkbon from its own detail page used to end on a 404: destroy()
 * returned redirect()->back(), and "back" was the show route of the record that
 * had just been deleted, so route model binding could not resolve it anymore.
 */
class ServiceOrderDeleteRedirectTest extends TestCase
{

    public function test_deleting_from_the_detail_page_lands_on_the_index(): void
    {
        $user = $this->serviceOrderManager();
        $service_order = $this->deletableServiceOrder();

        $response = $this->actingAs($user)
            ->from(route('serviceorders.show', $service_order))
            ->delete(route('serviceorders.destroy', $service_order));

        $this->assertDatabaseMissing('service_orders', ['id' => $service_order->id]);
        $response->assertRedirect(route('serviceorders.index'));

        $follow = $this->actingAs($user)->get($response->headers->get('Location'));

        $this->assertSame(200, $follow->getStatusCode());
    }

    public function test_deleting_from_the_index_returns_to_the_filtered_index(): void
    {
        $user = $this->serviceOrderManager();
        $service_order = $this->deletableServiceOrder();
        $filtered_index = route('serviceorders.index') . '?search=foo&page=2';

        $response = $this->actingAs($user)
            ->from($filtered_index)
            ->delete(route('serviceorders.destroy', $service_order));

        $this->assertDatabaseMissing('service_orders', ['id' => $service_order->id]);
        $response->assertRedirect($filtered_index);
    }

    private function serviceOrderManager(): User
    {
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'werkbon-manager']);

        foreach (['serviceorder.delete', 'serviceorder.read'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    private function deletableServiceOrder(): ServiceOrder
    {
        Customer::factory()->create();

        return ServiceOrder::factory()->create(['sent_to_administration' => false]);
    }
}
