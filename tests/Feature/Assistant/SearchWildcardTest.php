<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductType;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A search has to look for what it was given, not for anything at all.
 *
 * Per cent and underscore mean "anything" to LIKE, and nothing escaped them: a
 * search for "%" became %%% and matched every row in the table. That came back
 * looking like a list of results, so an assistant asked for a product "50%" would
 * have been handed the whole catalogue and had no way to know.
 *
 * Not a security hole, since the values are bound. A search that lies about what
 * it found is its own kind of bad.
 */
class SearchWildcardTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->count(3)->create([
            'brand_id' => Brand::factory()->create(['name' => 'Mitsubishi'])->id,
            'product_type_id' => ProductType::factory()->create(['name' => 'Airco'])->id,
        ]);

        Customer::factory()->count(3)->create();
        Asset::factory()->count(3)->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function found(string $tool, array $arguments, string $key): int
    {
        $result = app(ToolExecutor::class)->run(new ToolCall(
            $tool,
            $arguments,
            $this->userWithPermissions('product.read', 'customer.read', 'asset.read', 'ticket.read', 'serviceorder.read'),
        ));

        return is_array($result->content) ? count($result->content[$key] ?? []) : 0;
    }

    public function test_a_wildcard_matches_nothing_rather_than_everything(): void
    {
        foreach (['%', '_', '%%', '%_%'] as $wildcard) {
            $this->assertSame(0, $this->found('find_products', ['query' => $wildcard], 'products'), 'products for ' . $wildcard);
            $this->assertSame(0, $this->found('find_customer', ['query' => $wildcard], 'customers'), 'customers for ' . $wildcard);
            $this->assertSame(0, $this->found('find_asset', ['serial_number' => $wildcard], 'assets'), 'assets for ' . $wildcard);
            $this->assertSame(0, $this->found('find_tickets', ['query' => $wildcard], 'tickets'), 'tickets for ' . $wildcard);
            $this->assertSame(0, $this->found('search_service_orders', ['query' => $wildcard], 'service_orders'), 'orders for ' . $wildcard);
        }
    }

    public function test_a_real_search_still_finds_what_it_should(): void
    {
        $this->assertSame(3, $this->found('find_products', ['query' => 'Mitsubishi'], 'products'));
        $this->assertSame(3, $this->found('find_products', ['product_type' => 'Airco'], 'products'));
    }

    /**
     * A term containing a per cent sign must not spread to the rest of the table.
     *
     * Asserted as "does not match everything" rather than "matches the one row",
     * because the escape character is the driver's business: MySQL takes a
     * backslash by default and finds the row, SQLite wants an explicit ESCAPE and
     * finds nothing. These tests run on SQLite and the customers run on MySQL, so
     * the property worth pinning is the one both agree on.
     */
    public function test_a_term_with_a_wildcard_in_it_does_not_spread(): void
    {
        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Tyfocor'])->id,
            'product_type_id' => ProductType::factory()->create(['name' => 'Vloeistof'])->id,
            'model' => '50% glycol',
        ]);

        $found = $this->found('find_products', ['query' => '50% glycol'], 'products');

        $this->assertLessThanOrEqual(1, $found, 'a term with a wildcard matched more than the row that has it');
        $this->assertSame(0, $this->found('find_products', ['query' => '%glycol%'], 'products'));
    }
}
