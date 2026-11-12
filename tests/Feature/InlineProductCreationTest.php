<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A machine is registered on a product, a product on a brand and a type. Whoever
 * finds one of those missing halfway through the machine form makes it on the
 * spot, so each of these endpoints answers a JSON request with the record it made
 * — and keeps answering the pages it already served the way it always did.
 *
 * Each step asks for its own permission: being allowed to add a machine is not
 * being allowed to grow the product catalogue.
 */
class InlineProductCreationTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private Brand $brand;

    private ProductType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::factory()->create(['name' => 'Bosch']);
        $this->type = ProductType::factory()->create(['name' => 'Boormachine', 'typical_certificate_days' => 180]);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'product_type_id' => $this->type->id,
            'brand_id' => $this->brand->id,
            'model' => 'GSR 18V-55',
            'bundle' => false,
            'registable' => true,
        ], $overrides);
    }

    public function test_a_product_cannot_be_made_without_the_permission_to_make_products(): void
    {
        $this->actingAs($this->userWith('asset.create'))
            ->postJson('/products', $this->productPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['model' => 'GSR 18V-55']);
    }

    public function test_a_product_made_from_the_machine_form_comes_back_as_a_picker_option(): void
    {
        $response = $this->actingAs($this->userWith('product.create'))
            ->postJson('/products', $this->productPayload())
            ->assertCreated();

        $product = Product::where('model', 'GSR 18V-55')->sole();

        $response->assertExactJson([
            'id' => $product->id,
            'name' => 'Bosch GSR 18V-55 (Boormachine)',
            'bundle' => false,
            'registable' => true,
            'typical_certificate_days' => null,
            'product_type_typical_certificate_days' => 180,
        ]);
    }

    public function test_the_products_page_still_lands_on_the_product_list_after_saving(): void
    {
        $this->actingAs($this->userWith('product.create'))
            ->post('/products', $this->productPayload())
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Product aangemaakt.');
    }

    public function test_a_refused_product_names_the_field_it_is_about(): void
    {
        $this->actingAs($this->userWith('product.create'))
            ->postJson('/products', $this->productPayload(['model' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('model');
    }

    public function test_the_product_form_gets_its_brands_types_and_attributes_in_one_request(): void
    {
        $attribute = ProductAttribute::create(['name' => 'Spanning']);
        $value = $attribute->values()->create(['value' => '18V']);
        $attribute->productTypes()->attach($this->type->id);
        ProductAttribute::create(['name' => 'Zonder waarden']);

        $response = $this->actingAs($this->userWith('product.create'))
            ->getJson('/products/create')
            ->assertOk();

        $response->assertJsonFragment(['id' => $this->brand->id, 'name' => 'Bosch']);
        $response->assertJsonFragment(['id' => $this->type->id, 'name' => 'Boormachine']);
        $response->assertJsonPath('productAttributes', [[
            'id' => $attribute->id,
            'name' => 'Spanning',
            'values' => [['id' => $value->id, 'value' => '18V']],
            'product_type_ids' => [$this->type->id],
        ]]);
    }

    public function test_the_product_form_options_are_only_for_who_may_make_products(): void
    {
        $this->actingAs($this->userWith('asset.create'))
            ->getJson('/products/create')
            ->assertForbidden();
    }

    public function test_a_brand_cannot_be_made_without_the_permission_to_make_brands(): void
    {
        $this->actingAs($this->userWith('product.create'))
            ->postJson('/brands', ['name' => 'Makita'])
            ->assertForbidden();

        $this->assertDatabaseMissing('brands', ['name' => 'Makita']);
    }

    public function test_a_brand_made_from_the_product_form_comes_back_as_json(): void
    {
        $response = $this->actingAs($this->userWith('brand.create'))
            ->postJson('/brands', ['name' => 'Makita'])
            ->assertCreated();

        $response->assertJson(['id' => Brand::where('name', 'Makita')->sole()->id, 'name' => 'Makita']);
    }

    public function test_a_brand_that_already_exists_is_refused_on_its_name(): void
    {
        $this->actingAs($this->userWith('brand.create'))
            ->postJson('/brands', ['name' => 'Bosch'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_the_brands_page_still_lands_on_the_brand_list_after_saving(): void
    {
        $this->actingAs($this->userWith('brand.create'))
            ->post('/brands', ['name' => 'Makita'])
            ->assertRedirect(route('brands.index'))
            ->assertSessionHas('success', 'Merk aangemaakt.');
    }

    public function test_a_product_type_cannot_be_made_without_the_permission_to_make_product_types(): void
    {
        $this->actingAs($this->userWith('product.create'))
            ->postJson('/producttypes', ['name' => 'Schroefmachine'])
            ->assertForbidden();

        $this->assertDatabaseMissing('product_types', ['name' => 'Schroefmachine']);
    }

    public function test_a_product_type_made_from_the_product_form_comes_back_as_json(): void
    {
        $response = $this->actingAs($this->userWith('producttype.create'))
            ->postJson('/producttypes', ['name' => 'Schroefmachine'])
            ->assertCreated();

        $response->assertJson([
            'id' => ProductType::where('name', 'Schroefmachine')->sole()->id,
            'name' => 'Schroefmachine',
        ]);
    }

    public function test_the_product_types_page_still_lands_on_the_type_list_after_saving(): void
    {
        $this->actingAs($this->userWith('producttype.create'))
            ->post('/producttypes', ['name' => 'Schroefmachine'])
            ->assertRedirect(route('producttypes.index'))
            ->assertSessionHas('success', 'Producttype aangemaakt.');
    }

    public function test_the_product_picker_still_offers_products_in_the_same_shape(): void
    {
        $product = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->type->id,
            'model' => 'GBH 2-26',
            'typical_certificate_days' => 365,
            'bundle' => false,
            'registable' => true,
        ]);

        $this->actingAs($this->userWith('product.read'))
            ->getJson('/combo/products?q=GBH')
            ->assertOk()
            ->assertExactJson([[
                'id' => $product->id,
                'name' => 'Bosch GBH 2-26 (Boormachine)',
                'bundle' => false,
                'registable' => true,
                'typical_certificate_days' => 365,
                'product_type_typical_certificate_days' => 180,
            ]]);
    }

    public function test_a_machine_can_be_registered_on_a_product_made_a_moment_earlier(): void
    {
        $this->actingAs($this->userWithPermissions('asset.create', 'product.create', 'brand.create', 'producttype.create'));

        $product_id = $this->postJson('/products', $this->productPayload([
            'brand_id' => $this->postJson('/brands', ['name' => 'Hilti'])->json('id'),
            'product_type_id' => $this->postJson('/producttypes', ['name' => 'Kernboor'])->json('id'),
            'model' => 'DD 150-U',
        ]))->json('id');

        $this->post('/assets', [
            'product_id' => $product_id,
            'customer_id' => Customer::factory()->create()->id,
            'serial_number' => 'HIL-0001',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assets', ['product_id' => $product_id, 'serial_number' => 'HIL-0001']);
    }
}
