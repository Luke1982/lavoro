<?php

namespace Tests\Feature\Tenancy;

use App\Mail\ServiceOrderPdfMail;
use App\Mail\TicketInfoRequestMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Whatever a customer puts in lives on the tenant's disk. storage_path() points
 * at the shared folder instead, which under tenancy holds nobody's files: logos
 * and photos dropped out of pdfs and mails without an error, and an imported
 * image landed where every customer could overwrite it.
 */
class TenantFilesStayOnTheTenantDiskTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('logos/logo.png', base64_decode(self::PNG));

        Company::main()->update(['name' => 'Koeltechniek Noord', 'logo_path' => 'logos/logo.png']);
    }

    public function test_the_pdf_logo_comes_from_the_tenant_disk(): void
    {
        $this->assertStringStartsWith('data:image/png;base64,', (string) Company::pdfLogo()['data']);
    }

    /** Embedded, not linked: the url to the file needs a login the customer does not have. */
    public function test_a_mail_to_a_customer_carries_the_logo_of_the_tenant(): void
    {
        Mail::to('klant@example.nl')->send(new TicketInfoRequestMail('Aanvullende informatie', '<p>Graag een foto.</p>', 'https://example.nl/upload'));

        $message = $this->sent();

        $this->assertCount(1, array_filter($message->getAttachments(), fn ($part) => $part->getDisposition() === 'inline'));
        $this->assertStringContainsString('cid:', (string) $message->getHtmlBody());
    }

    /** Signed with the app's own name, every customer's werkbon came from "Lavoro". */
    public function test_a_werkbon_mail_comes_from_the_company(): void
    {
        Mail::to('klant@example.nl')->send(new ServiceOrderPdfMail(ServiceOrder::factory()->create(), '%PDF-1.4'));

        $message = $this->sent();

        $this->assertStringContainsString('Koeltechniek Noord', (string) $message->getHtmlBody());
        $this->assertStringContainsString('cid:', (string) $message->getHtmlBody());
    }

    public function test_an_imported_image_lands_on_the_tenant_disk(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('images.importFromUrl'), [
                'url' => 'data:image/png;base64,' . self::PNG,
                'imageable_type' => Product::class,
                'imageable_id' => $product->id,
            ])
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists($product->images()->sole()->path);
    }

    private function sent(): Email
    {
        return app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    }
}
