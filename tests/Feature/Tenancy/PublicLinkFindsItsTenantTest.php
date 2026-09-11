<?php

namespace Tests\Feature\Tenancy;

use App\Enums\AccessTokenPurpose;
use App\Enums\TicketStatusses;
use App\Models\AccessToken;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesASecondTenant;
use Tests\TestCase;

/**
 * A customer opening the link from the mail has no session, so nothing but the
 * link can say whose database it belongs to. Opened in the browser a colleague
 * is logged in with, the session hid that: there it found the tenant, and for
 * the customer the page did not open.
 *
 * On the second tenant: opening a tenant throws the test's transaction away,
 * and everything made in it with it. That one keeps what is put in it.
 */
class PublicLinkFindsItsTenantTest extends TestCase
{
    use UsesASecondTenant;

    private function ticket(): Ticket
    {
        return Ticket::factory()->create([
            'asset_id' => Asset::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
                'product_id' => Product::factory()->create()->id,
                'serial_number' => 'SN-40417',
            ])->id,
            'status' => TicketStatusses::wacht_op_klant->value,
            'priority' => 'Normaal',
        ]);
    }

    public function test_a_customer_without_a_session_reaches_the_page(): void
    {
        $url = $this->asTenant($this->secondTenant(), fn () => AccessToken::issue(
            $this->ticket(), AccessTokenPurpose::ticket_customer_upload
        )->url());

        tenancy()->end();

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/TicketUploadPage')
                ->where('serial', 'SN-40417'));

        $this->assertFalse(tenancy()->initialized, 'the tenant opened for the link should be closed after it');
    }

    /** Links sent before the tenant was part of them live on for their fourteen days. */
    public function test_a_link_from_before_the_tenant_was_in_it_still_opens(): void
    {
        $plaintext = str_repeat('k7Q2', 12);

        $this->asTenant($this->secondTenant(), function () use ($plaintext) {
            $ticket = $this->ticket();

            $token = new AccessToken([
                'tokenable_type' => $ticket->getMorphClass(),
                'tokenable_id' => $ticket->id,
                'purpose' => AccessTokenPurpose::ticket_customer_upload,
                'expires_at' => now()->addDays(3),
            ]);
            $token->token_hash = AccessToken::hash($plaintext);
            $token->save();
        });

        tenancy()->end();

        $this->get(route('public.ticket.upload', ['token' => $plaintext]))->assertOk();
    }

    public function test_a_link_naming_a_tenant_that_does_not_exist_is_not_found(): void
    {
        tenancy()->end();

        $this->get('/storing/informatie/no-such-tenant_' . str_repeat('x', 48))->assertNotFound();
    }

    /** The file route sits behind the login, so the customer gets the logo inline. */
    public function test_the_customer_sees_the_logo_without_logging_in(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        Company::main()->update(['logo_path' => 'logos/logo.png']);

        $this->get(AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload)->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.logo_url', fn (string $url) => str_starts_with($url, 'data:image/png;base64,')));
    }
}
