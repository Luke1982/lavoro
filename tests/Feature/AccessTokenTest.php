<?php

namespace Tests\Feature;

use App\Enums\AccessTokenPurpose;
use App\Models\AccessToken;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The token subsystem knows nothing about storingen. These exercise it as the
 * generic thing it is: issue against any record, resolve against one purpose,
 * and refuse everything that is not exactly that.
 */
class AccessTokenTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')
            ->get('storing/informatie/{token}', fn () => response()->json([
                'id' => app(AccessToken::class)->id,
            ]))
            ->middleware('accesstoken:ticket.customer_upload')
            ->name('public.ticket.upload');

        /** Routes die na het opstarten bijkomen staan pas na een verversing onder hun naam. */
        Route::getRoutes()->refreshNameLookups();
    }

    private function ticket(): Ticket
    {
        $customer = Customer::factory()->create();
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => Product::factory()->create()->id,
        ]);

        return Ticket::factory()->create(['asset_id' => $asset->id, 'status' => 'Open']);
    }

    public function test_issue_returns_a_url_and_stores_only_the_hash(): void
    {
        $ticket = $this->ticket();

        $issued = AccessToken::issue(
            $ticket,
            AccessTokenPurpose::ticket_customer_upload,
            'klant@example.nl',
            ['requested' => ['photos']],
        );

        $this->assertStringContainsString($issued->plaintext, $issued->url());
        $this->assertDatabaseMissing('access_tokens', ['token_hash' => $issued->plaintext]);
        $this->assertSame(hash('sha256', $issued->plaintext), $issued->token->token_hash);
        $this->assertSame(['requested' => ['photos']], $issued->token->payload);
        $this->assertSame('klant@example.nl', $issued->token->recipient);
        $this->assertTrue($issued->token->tokenable->is($ticket));
    }

    public function test_resolve_finds_a_usable_token(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);

        $resolved = AccessToken::resolve($issued->plaintext, AccessTokenPurpose::ticket_customer_upload);

        $this->assertNotNull($resolved);
        $this->assertSame($issued->token->id, $resolved->id);
        $this->assertTrue($resolved->isUsable());
    }

    public function test_resolve_refuses_a_token_that_is_not_this_purpose(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);

        /** Rechtstreeks, want de cast laat een doel dat nog niet bestaat er niet in. */
        DB::table('access_tokens')->where('id', $issued->token->id)->update(['purpose' => 'something.else']);

        $this->assertNull(AccessToken::resolve($issued->plaintext, AccessTokenPurpose::ticket_customer_upload));
    }

    public function test_a_revoked_token_does_not_resolve(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);
        $issued->token->revoke(User::factory()->create());

        $this->assertNull(AccessToken::resolve($issued->plaintext, AccessTokenPurpose::ticket_customer_upload));
    }

    public function test_an_expired_token_resolves_but_reports_itself_expired(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);
        $issued->token->update(['expires_at' => now()->subMinute()]);

        $resolved = AccessToken::resolve($issued->plaintext, AccessTokenPurpose::ticket_customer_upload);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->isExpired());
        $this->assertFalse($resolved->isUsable());
    }

    public function test_mark_used_counts_and_stamps(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);

        $issued->token->markUsed();
        $issued->token->markUsed();

        $this->assertSame(2, $issued->token->fresh()->use_count);
        $this->assertNotNull($issued->token->fresh()->last_used_at);
    }

    public function test_the_middleware_hands_the_resolved_token_to_the_route(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);

        $this->get($issued->url())
            ->assertOk()
            ->assertJson(['id' => $issued->token->id]);
    }

    public function test_the_middleware_404s_on_an_unknown_token(): void
    {
        $this->get(route('public.ticket.upload', ['token' => 'nonsense']))->assertNotFound();
    }

    public function test_the_middleware_renders_the_expired_page_on_a_stale_token(): void
    {
        $issued = AccessToken::issue($this->ticket(), AccessTokenPurpose::ticket_customer_upload);
        $issued->token->update(['expires_at' => now()->subDay()]);

        $this->get($issued->url())->assertStatus(410);
    }
}
