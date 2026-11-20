<?php

namespace Tests\Feature\Quotes;

use App\Models\Customer;
use App\Models\ServiceOrder;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Quotes are a module a customer subscribes to. Without it nothing of quotes
 * answers, not even to someone holding every right, and the pages that offer
 * a way into quotes do not offer it.
 */
class QuoteModuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_without_the_module_nothing_of_quotes_answers(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        tenancy()->tenant->modules = ['invoices'];
        $this->actingAs($this->admin());

        foreach (['/quotes', '/quote-settings', '/quote-terms', "/quotes/origin/serviceorder/{$order->id}"] as $url) {
            $this->getJson($url)->assertForbidden();
        }

        $this->postJson('/quotes', ['title' => 'X', 'customer_id' => $order->customer_id])->assertForbidden();
    }

    public function test_the_pages_know_whether_the_module_is_there(): void
    {
        $this->actingAs($this->admin());

        $this->get('/quotes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenant.modules', fn ($modules) => collect($modules)->contains('quotes')));
    }
}
