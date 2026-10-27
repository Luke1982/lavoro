<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The photo flow's two writes: a product into the catalogue, growing whatever
 * it hangs from, and then the actual machine on the wall with the serial the
 * typeplaatje shows.
 */
class PhotoFlowToolsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function maker(): User
    {
        return $this->userWithPermissions('assistant.use', 'product.create', 'asset.create', 'product.read');
    }

    private function carryOut(string $tool, array $arguments, ?User $user = null): ToolResult
    {
        $user ??= $this->maker();

        return app(ToolExecutor::class)->run(new ToolCall(
            $tool,
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for($tool, $arguments, $user)->encoded(),
        ));
    }

    public function test_a_product_grows_everything_it_hangs_from(): void
    {
        $made = $this->carryOut('create_product', [
            'brand' => 'Mitsubishi Heavy',
            'product_type' => 'Airco binnendeel multisplit',
            'model' => 'SRK 25 ZS-WF',
            'description' => 'Met ingebouwde wifi-module',
            'attributes' => ['Koelvermogen' => '2,5 kW'],
        ]);

        $this->assertFalse($made->is_error, (string) $made->summary);
        $this->assertTrue($made->content['brand_was_new']);

        $product = Product::findOrFail($made->content['product_id']);

        $this->assertSame('Mitsubishi Heavy', $product->brand->name);
        $this->assertSame('Airco binnendeel multisplit', $product->productType->name);
        $this->assertSame('Met ingebouwde wifi-module', $product->description);

        $attribute = ProductAttribute::where('name', 'Koelvermogen')->sole();

        $this->assertSame('2,5 kW', $attribute->values()->sole()->value);
        $this->assertTrue(
            $attribute->productTypes()->whereKey($product->product_type_id)->exists(),
            'the kenmerk was never linked to the soort, so no form will ever show it',
        );
        /** Loaded the way the pages load it, because the accessor is empty otherwise. */
        $this->assertSame(
            [['name' => 'Koelvermogen', 'value' => '2,5 kW']],
            $product->fresh()
                ->load('productAttributeValueables.productAttribute', 'productAttributeValueables.value')
                ->specific_attributes,
        );
    }

    /**
     * The catalogue already holds near-duplicate brands typed by hand. Matching
     * case-insensitively is what keeps "mitsubishi" from landing beside
     * "Mitsubishi", and the same model twice is the same product twice.
     */
    public function test_existing_pieces_are_reused_whatever_the_capitals_say(): void
    {
        $brand = Brand::factory()->create(['name' => 'Mitsubishi Heavy']);
        ProductType::create(['name' => 'Airco binnendeel multisplit']);

        $made = $this->carryOut('create_product', [
            'brand' => 'MITSUBISHI heavy',
            'product_type' => 'airco BINNENDEEL multisplit',
            'model' => 'SRK 35 ZS-W',
        ]);

        $this->assertFalse($made->is_error);
        $this->assertSame($brand->id, $made->content['brand_id']);
        $this->assertFalse($made->content['brand_was_new']);
        $this->assertSame(1, Brand::count(), 'a second spelling of the same brand slipped in');
        $this->assertSame(1, ProductType::count());
    }

    public function test_the_same_model_of_the_same_brand_is_refused_with_its_number(): void
    {
        $brand = Brand::factory()->create(['name' => 'Mitsubishi Heavy']);
        $existing = Product::factory()->create(['brand_id' => $brand->id, 'model' => 'SRK 25 ZS-WF']);

        $again = $this->carryOut('create_product', [
            'brand' => 'mitsubishi heavy',
            'product_type' => 'Airco',
            'model' => 'srk 25 zs-wf',
        ]);

        $this->assertTrue($again->is_error, 'the same product went in twice');
        $this->assertStringContainsString('#' . $existing->id, (string) $again->summary);
        $this->assertSame(1, Product::count());
    }

    /** The preview says which pieces the button will create, by name. */
    public function test_the_preview_names_what_is_new_and_what_already_exists(): void
    {
        Brand::factory()->create(['name' => 'Mitsubishi Heavy']);
        $user = $this->maker();

        $proposal = app(ToolExecutor::class)->run(new ToolCall('create_product', [
            'brand' => 'Mitsubishi Heavy',
            'product_type' => 'Warmtepomp',
            'model' => 'SRK 50',
        ], $user));

        $preview = $proposal->content['preview'];

        $this->assertStringContainsString('merk Mitsubishi Heavy bestaat al', $preview);
        $this->assertStringContainsString('maakt soort "Warmtepomp" NIEUW aan', $preview);
        $this->assertSame(0, Product::count(), 'the preview created something');
    }

    public function test_creating_products_needs_the_permission(): void
    {
        $result = $this->carryOut('create_product', [
            'brand' => 'X',
            'product_type' => 'Y',
            'model' => 'Z',
        ], $this->userWith('assistant.use'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Product::count());
    }

    public function test_a_machine_lands_at_the_customer_with_its_serial(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();

        $made = $this->carryOut('create_asset', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => '1101007320',
        ]);

        $this->assertFalse($made->is_error, (string) $made->summary);

        $asset = Asset::findOrFail($made->content['asset_id']);

        $this->assertSame('1101007320', $asset->serial_number);
        $this->assertSame($customer->id, $asset->customer_id);
    }

    /** The same serial twice is the same machine photographed twice. */
    public function test_a_known_serial_is_refused_with_the_machine_it_belongs_to(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $twin = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => '1101007320',
        ]);

        $again = $this->carryOut('create_asset', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => '1101007320',
        ]);

        $this->assertTrue($again->is_error);
        $this->assertStringContainsString('#' . $twin->id, (string) $again->summary);
        $this->assertSame(1, Asset::count());
    }

    public function test_a_location_of_another_customer_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $elsewhere = Location::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $result = $this->carryOut('create_asset', [
            'customer_id' => $customer->id,
            'product_id' => Product::factory()->create()->id,
            'serial_number' => 'SN-1',
            'location_id' => $elsewhere->id,
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Asset::count());
    }
}
