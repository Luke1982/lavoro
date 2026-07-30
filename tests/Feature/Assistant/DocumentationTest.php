<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\ProductDocumentation;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Deciding which of a product's documents may be sent to a supplier.
 *
 * The filter is the feature. A contract or an invoice filed against a product is
 * somebody's commercial paperwork, and sending it off to answer "what is the
 * refrigerant" is not a mistake anybody would notice happening.
 */
class DocumentationTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function documentOn(Product $product, string $category, string $extension = 'pdf', int $kilobytes = 20): Document
    {
        $path = 'uploaded/product/' . $product->id . '/documents/' . uniqid() . '.' . $extension;
        Storage::put($path, str_repeat('x', $kilobytes * 1024));

        $document = Document::create([
            'name' => 'bestand.' . $extension,
            'title' => $category . ' voor ' . $product->model,
            'document_category_id' => DocumentCategory::firstOrCreate(['name' => $category])->id,
            'user_id' => User::factory()->create()->id,
            'path' => $path,
            'size' => $kilobytes * 1024,
        ]);

        $product->documents()->attach($document->id);

        return $document;
    }

    private function reader(): ProductDocumentation
    {
        return app(ProductDocumentation::class);
    }

    private function reader_user(): User
    {
        return $this->userWith('product.read');
    }

    public function test_a_manual_is_picked_up(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Handleidingen');

        $this->assertCount(1, $this->reader()->for($product, $this->reader_user()));
    }

    public function test_technical_documentation_is_picked_up(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Technische documentatie');

        $this->assertCount(1, $this->reader()->for($product, $this->reader_user()));
    }

    /**
     * The one that matters. Nobody would notice this going out.
     */
    public function test_contracts_and_invoices_are_left_where_they_are(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Contracten');
        $this->documentOn($product, 'Facturen');
        $this->documentOn($product, 'Offertes');

        $this->assertCount(0, $this->reader()->for($product, $this->reader_user()));
    }

    public function test_an_unfiled_document_is_left_out(): void
    {
        $product = Product::factory()->create();
        $document = $this->documentOn($product, 'Handleidingen');
        $document->update(['document_category_id' => null]);

        $this->assertCount(0, $this->reader()->for($product, $this->reader_user()));
    }

    /**
     * Sent as bytes, an .odt is something nobody on the other end can read.
     */
    public function test_only_pdfs_travel(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Handleidingen', 'odt');

        $this->assertCount(0, $this->reader()->for($product, $this->reader_user()));
    }

    public function test_something_far_too_big_is_left_out(): void
    {
        config(['assistant.max_document_kilobytes' => 100]);

        $product = Product::factory()->create();
        $this->documentOn($product, 'Handleidingen', 'pdf', 500);

        $this->assertCount(0, $this->reader()->for($product, $this->reader_user()));
    }

    public function test_only_so_many_go_at_once(): void
    {
        config(['assistant.max_documents_per_question' => 2]);

        $product = Product::factory()->create();
        foreach (range(1, 5) as $ignored) {
            $this->documentOn($product, 'Handleidingen');
        }

        $this->assertCount(2, $this->reader()->for($product, $this->reader_user()));
    }

    /**
     * A file gone missing from the disk must not take the whole question with it.
     */
    public function test_a_missing_file_is_skipped_rather_than_fatal(): void
    {
        $product = Product::factory()->create();
        $document = $this->documentOn($product, 'Handleidingen');
        Storage::delete($document->path);

        $this->assertCount(0, $this->reader()->for($product, $this->reader_user()));
    }

    public function test_somebody_who_may_not_read_products_gets_nothing(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Handleidingen');

        $this->assertCount(0, $this->reader()->for($product, $this->userWith('serviceorder.read')));
    }

    /**
     * Somebody in front of a machine asking how to bleed it wants the manual for
     * the model, which is filed against the product — machines hold no documents
     * of their own here, so the product is the only source.
     */
    public function test_a_machine_gets_the_manual_of_its_product(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Handleidingen');
        $asset = Asset::factory()->create(['customer_id' => Customer::factory()->create()->id, 'product_id' => $product->id]);

        $this->assertCount(1, $this->reader()->forAsset($asset->load('product'), $this->reader_user()));
    }

    public function test_the_tool_says_so_when_there_is_nothing(): void
    {
        $product = Product::factory()->create();

        $result = app(ToolExecutor::class)->run(new ToolCall(
            'read_documentation', ['product_id' => $product->id], $this->reader_user()
        ));

        $this->assertFalse($result->is_error);
        $this->assertSame([], $result->content['documents']);
        $this->assertStringContainsString('geen documentatie', $result->content['note']);
    }

    public function test_the_tool_hands_the_file_over_to_be_read(): void
    {
        $product = Product::factory()->create();
        $this->documentOn($product, 'Handleidingen');

        $result = app(ToolExecutor::class)->run(new ToolCall(
            'read_documentation', ['product_id' => $product->id], $this->reader_user()
        ));

        $this->assertCount(1, $result->attachments);
        $this->assertSame('application/pdf', $result->attachments[0]->media_type);
        $this->assertNotEmpty($result->attachments[0]->base64);
    }
}
