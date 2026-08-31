<?php

namespace Tests\Feature;

use App\Enums\AccessTokenPurpose;
use App\Enums\TicketStatusses;
use App\Mail\TicketInfoRequestMail;
use App\Models\AccessToken;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * De aanvraag zelf: wat er in de mail staat, welke link eronder hangt en wat de
 * storing ervan merkt.
 */
class TicketInfoRequestTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function ticket(string $customer_email = 'klant@example.nl'): Ticket
    {
        $customer = Customer::factory()->create([
            'name' => 'Van Dijk B.V.',
            'email' => $customer_email,
        ]);

        $product = Product::factory()->create([
            'brand_id' => Brand::create(['name' => 'Kubota'])->id,
            'model' => 'KX027',
        ]);

        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => 'SN-88213',
        ]);

        return Ticket::factory()->create([
            'asset_id' => $asset->id,
            'status' => TicketStatusses::open->value,
            'priority' => 'Normaal',
        ]);
    }

    private function sender(): User
    {
        return $this->userWithPermissions('ticket.request_customer_info', 'ticket.read', 'ticket.change_status');
    }

    public function test_the_defaults_name_the_customer_the_machine_and_the_serial(): void
    {
        $ticket = $this->ticket();

        $response = $this->actingAs($this->sender())
            ->getJson('/api/tickets/' . $ticket->id . '/info-request')
            ->assertOk();

        $this->assertSame('klant@example.nl', $response->json('to'));
        $this->assertSame('Wij ontvangen graag extra informatie over uw storing', $response->json('subject'));
        $this->assertStringContainsString('Van Dijk B.V.', $response->json('body'));
        $this->assertStringContainsString('Kubota KX027', $response->json('body'));
        $this->assertStringContainsString('SN-88213', $response->json('body'));
        $this->assertSame(['photos', 'videos'], $response->json('requested'));
        $this->assertCount(3, $response->json('options'));
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->userWith('ticket.read'))
            ->postJson('/api/tickets/' . $ticket->id . '/info-request', [
                'to' => 'klant@example.nl',
                'subject' => 'Onderwerp',
                'body' => '<p>Tekst</p>',
                'requested' => ['photos'],
            ])
            ->assertForbidden();

        $this->assertSame(0, AccessToken::count());
    }

    public function test_sending_mails_the_customer_issues_a_link_and_moves_the_status(): void
    {
        Mail::fake();
        $ticket = $this->ticket();

        $this->actingAs($this->sender())
            ->postJson('/api/tickets/' . $ticket->id . '/info-request', [
                'to' => 'anders@example.nl',
                'subject' => 'Wij ontvangen graag extra informatie over uw storing',
                'body' => '<p>Beste klant,</p><ul><li>foto\'s van de storing</li></ul>',
                'requested' => ['photos', 'other'],
            ])
            ->assertOk();

        $token = AccessToken::sole();
        $this->assertSame(AccessTokenPurpose::ticket_customer_upload, $token->purpose);
        $this->assertTrue($token->tokenable->is($ticket));
        $this->assertSame('anders@example.nl', $token->recipient);
        $this->assertSame(['requested' => ['photos', 'other']], $token->payload);

        Mail::assertSent(TicketInfoRequestMail::class, function (TicketInfoRequestMail $mail) {
            return $mail->hasTo('anders@example.nl')
                && str_contains($mail->upload_url, '/storing/informatie/')
                && str_contains($mail->body_html, 'Beste klant');
        });

        $this->assertSame(
            TicketStatusses::wacht_op_klant->value,
            $ticket->fresh()->status,
        );
    }

    public function test_sending_writes_an_email_activity_naming_the_recipient(): void
    {
        Mail::fake();
        $ticket = $this->ticket();

        $this->actingAs($this->sender())
            ->postJson('/api/tickets/' . $ticket->id . '/info-request', [
                'to' => 'klant@example.nl',
                'subject' => 'Onderwerp',
                'body' => '<p>Tekst</p>',
                'requested' => ['photos', 'videos'],
            ])
            ->assertOk();

        $activity = Activity::where('event_key', 'ticket.info_requested')->sole();

        $this->assertSame('email', $activity->category);
        $this->assertSame(Ticket::class, $activity->subject_type);
        $this->assertStringContainsString('klant@example.nl', $activity->description);
        $this->assertStringContainsString("foto's van de storing", $activity->description);
        $this->assertSame(['photos', 'videos'], $activity->metadata['requested']);
    }

    public function test_at_least_one_kind_of_information_must_be_requested(): void
    {
        Mail::fake();
        $ticket = $this->ticket();

        $this->actingAs($this->sender())
            ->postJson('/api/tickets/' . $ticket->id . '/info-request', [
                'to' => 'klant@example.nl',
                'subject' => 'Onderwerp',
                'body' => '<p>Tekst</p>',
                'requested' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested');

        Mail::assertNothingSent();
        $this->assertSame(0, AccessToken::count());
    }

    /**
     * Het logo hoort mee te reizen en niet vanaf onze server opgehaald te worden:
     * bijna elk mailprogramma laat externe afbeeldingen pas toe als de lezer erom
     * vraagt, en tot dat moment kijkt een klant naar een kapot plaatje.
     */
    public function test_the_company_logo_travels_with_the_mail(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('company-logos/logo.png', 'niet echt een png maar wel een bestand');
        Company::create([
            'name' => 'Spee Totaaltechniek',
            'logo_path' => 'company-logos/logo.png',
            'is_main' => true,
        ]);

        $ticket = $this->ticket();

        $this->actingAs($this->sender())
            ->postJson('/api/tickets/' . $ticket->id . '/info-request', [
                'to' => 'klant@example.nl',
                'subject' => 'Onderwerp',
                'body' => '<p>Tekst</p>',
                'requested' => ['photos'],
            ])
            ->assertOk();

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();
        $html = $sent->getHtmlBody();

        $this->assertStringContainsString('src="cid:', $html);
        $this->assertStringNotContainsString('/storage/company-logos/', $html);
        $this->assertCount(1, $sent->getAttachments());
    }

    public function test_a_mail_that_will_not_go_leaves_the_storing_as_it_was(): void
    {
        $ticket = $this->ticket();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp ligt eruit'));

        $this->actingAs($this->sender())
            ->postJson('/api/tickets/' . $ticket->id . '/info-request', [
                'to' => 'klant@example.nl',
                'subject' => 'Onderwerp',
                'body' => '<p>Tekst</p>',
                'requested' => ['photos'],
            ])
            ->assertStatus(500);

        $this->assertNotNull(AccessToken::sole()->revoked_at);
        $this->assertSame(TicketStatusses::open->value, $ticket->fresh()->status);
        $this->assertSame(0, Activity::where('event_key', 'ticket.info_requested')->count());
    }

    public function test_a_machine_without_a_serial_number_leaves_that_sentence_out(): void
    {
        $ticket = $this->ticket();
        $ticket->asset->update(['serial_number' => null]);

        $body = $this->actingAs($this->sender())
            ->getJson('/api/tickets/' . $ticket->id . '/info-request')
            ->assertOk()
            ->json('body');

        $this->assertStringContainsString('inzake Kubota KX027.', $body);
        $this->assertStringNotContainsString('serienummer', $body);
    }

    public function test_a_customer_without_an_address_still_opens_the_form(): void
    {
        $ticket = $this->ticket(customer_email: '');

        $this->actingAs($this->sender())
            ->getJson('/api/tickets/' . $ticket->id . '/info-request')
            ->assertOk()
            ->assertJson(['to' => null]);
    }
}
