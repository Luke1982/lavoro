<?php

namespace Tests\Feature\Quotes;

use App\Domain\Quotes\QuoteForCustomer;
use App\Domain\Quotes\QuoteSettings;
use App\Mail\AccessLinkMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\QuoteVersion;
use App\Support\PdfImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\Concerns\MakesQuotes;
use Tests\TestCase;

/**
 * What the customer receives, on the page, on paper and in the mail: who makes
 * the quote, the columns chosen, the terms it goes with, and for a private
 * customer the 14 days to change their mind. Nothing internal, and nowhere the
 * place a file is stored.
 */
class QuoteDocumentTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use MakesQuotes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->signIn($this->admin());
    }

    public function test_the_customer_receives_who_makes_it_the_columns_and_the_cooling_off_period(): void
    {
        Company::create(['name' => 'Koeltechniek B.V.', 'coc_number' => '12345678', 'iban' => 'NL91ABNA0417164300', 'is_main' => true]);
        $private = $this->makeQuote();
        $business = $this->makeQuote(Customer::factory()->create(['chamber_of_commerce_number' => '87654321']));
        $this->patch("/quotes/{$private->id}", ['layout' => ['unit_price' => false, 'line_total' => false]]);

        $document = QuoteForCustomer::of($private->fresh());

        $this->assertSame('12345678', $document['seller']['coc_number']);
        $this->assertSame('NL91ABNA0417164300', $document['seller']['iban']);
        $this->assertFalse($document['layout']['unit_price']);
        $this->assertTrue($document['layout']['quantity'], 'What the quote does not set comes from the settings.');
        $this->assertStringContainsString('Koeltechniek B.V.', $document['withdrawal']['form'][0]);
        $this->assertNull(QuoteForCustomer::of($business)['withdrawal']);
    }

    public function test_the_mail_carries_the_quote_and_the_terms_and_the_page_never_names_where_they_are_stored(): void
    {
        QuoteSettings::storeTerms(UploadedFile::fake()->create('Voorwaarden.pdf', 10, 'application/pdf'));
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $link = $this->sendQuote($quote);

        $this->assertSame(
            ['Offerte ' . $quote->number . ' versie 1.pdf', 'Voorwaarden.pdf'],
            array_column(Mail::sent(AccessLinkMail::class)->last()->files, 'name'),
        );

        $this->signOut();
        $this->get($link)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('terms', ['name' => 'Voorwaarden.pdf'])
            ->missing('quote.cost_price'));
        $this->get($link . '/voorwaarden')->assertOk();
        $this->get($link . '/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_quote_and_each_send_download_as_pdf(): void
    {
        $quote = $this->makeQuote();
        $this->addLine($quote);
        $this->sendQuote($quote);
        $send = $quote->versions()->sole();

        $this->get("/quotes/{$quote->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/quotes/{$quote->id}/versions/{$send->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_version_sent_before_quotes_said_all_this_still_opens(): void
    {
        $quote = $this->makeQuote();
        $old = QuoteVersion::create([
            'quote_id' => $quote->id,
            'number' => 1,
            'snapshot' => [
                'quote' => ['number' => $quote->number, 'title' => 'Oud', 'intro_text' => null, 'closing_text' => null,
                    'customer_picks_options' => true, 'quote_date' => '2026-11-01', 'valid_until' => '2026-12-01',
                    'customer' => 'Klant', 'contact' => null],
                'lines' => [['id' => 1, 'parent_id' => null, 'kind' => 'free', 'description' => 'Montage', 'details' => null,
                    'quantity' => 1, 'unit' => 'uur', 'unit_price' => 100, 'vat_percent' => 21, 'discount_percent' => 0,
                    'is_optional' => false, 'is_chosen' => null, 'net' => 100, 'children' => []]],
                'totals' => [
                    'net' => 100, 'vat' => [['rate' => 21, 'net' => 100, 'vat' => 21]],
                    'vat_total' => 21, 'gross' => 121, 'optional_net' => 0,
                ],
            ],
        ]);

        $this->get("/quotes/{$quote->id}/versions/{$old->id}")->assertOk();
        $this->get("/quotes/{$quote->id}/versions/{$old->id}/pdf")->assertOk();
    }

    public function test_a_photo_is_shrunk_and_a_drawing_loses_the_gradients_a_pdf_paints_black(): void
    {
        $photo = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagejpeg($photo);
        Storage::disk('public')->put('foto.jpg', ob_get_clean());
        Storage::disk('public')->put('tekening.svg', '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="body">'
            . '<stop offset="0" stop-color="#ffffff"/></linearGradient></defs><rect fill="url(#body)"/></svg>');

        [$width] = getimagesizefromstring(base64_decode(substr(PdfImage::dataUri('foto.jpg'), strlen('data:image/jpeg;base64,'))));
        $drawing = base64_decode(substr(PdfImage::dataUri('tekening.svg'), strlen('data:image/svg+xml;base64,')));

        $this->assertSame(240, $width);
        $this->assertStringContainsString('fill="#ffffff"', $drawing);
        $this->assertNull(PdfImage::dataUri('weg.jpg'));
    }
}
