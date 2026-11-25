<?php

namespace Tests\Feature\ReadRules;

use App\Models\Customer;
use App\Models\Document;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A document's file is fetched by id, so downloading and previewing ask the same
 * rule as reading the document (Document::visibleTo): the right to see
 * documents, on a record this person may open.
 */
class DocumentFileAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function documentOnAWerkbon(?User $executing = null): Document
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        if ($executing) {
            $order->syncExecutingUsers([$executing->id]);
        }

        $path = 'uploaded/serviceorder/' . $order->id . '/handleiding.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4');
        $document = Document::create(['name' => 'Handleiding.pdf', 'path' => $path]);
        $order->documents()->attach($document->id, ['internal' => false]);

        return $document;
    }

    public function test_a_document_on_a_werkbon_one_may_not_open_cannot_be_downloaded_or_previewed(): void
    {
        $monteur = $this->userWithPermissions('serviceorder.read_own', 'document.see');
        $document = $this->documentOnAWerkbon();

        $this->actingAs($monteur);
        $this->getJson("/documents/{$document->id}/download")->assertForbidden();
        $this->getJson("/documents/{$document->id}/preview")->assertForbidden();
    }

    public function test_a_document_on_ones_own_werkbon_can_be_downloaded_and_previewed(): void
    {
        $monteur = $this->userWithPermissions('serviceorder.read_own', 'document.see');
        $document = $this->documentOnAWerkbon($monteur);

        $this->actingAs($monteur);
        $this->get("/documents/{$document->id}/download")->assertOk()->assertDownload('Handleiding.pdf');
        $this->get("/documents/{$document->id}/preview")->assertOk();
    }

    public function test_opening_the_werkbon_without_the_right_to_see_documents_opens_none(): void
    {
        $user = $this->userWithPermissions('serviceorder.read');
        $document = $this->documentOnAWerkbon();

        $this->actingAs($user)->getJson("/documents/{$document->id}/download")->assertForbidden();
    }
}
