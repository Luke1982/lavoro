<?php

namespace Tests\Feature;

use App\Exceptions\GraphNotConfigured;
use App\Exceptions\MailNotConfigured;
use App\Exceptions\Refusal;
use App\Models\GeneralSetting;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantMailTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The rule behind task 32: keys are per tenant or they are not there. There is
 * no falling back to .env, because post from someone else's mailbox or bookings
 * in someone else's ledger are worse than a polite refusal.
 */
class IntegrationCredentialsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_snelstart_without_credentials_refuses_rather_than_falling_back(): void
    {
        config(['services.snelstart.client_key' => 'global-sleutel-die-niemand-mag-gebruiken']);
        Http::preventStrayRequests();

        $connection = IntegrationConnection::create([
            'connector' => 'snelstart',
            'is_active' => true,
            'settings' => IntegrationConnection::defaultSettings('snelstart'),
        ]);

        $this->expectException(Refusal::class);

        $connection->connector()->test();
    }

    public function test_smtp_mail_without_credentials_throws_rather_than_falling_back(): void
    {
        GeneralSetting::set('mail_transport', 'smtp');
        config(['mail.mailers.smtp.host' => 'mail.van-een-ander-bedrijf.nl']);

        $this->expectException(MailNotConfigured::class);

        app(TenantMailTransport::class)->make();
    }

    public function test_a_partially_configured_graph_tenant_does_not_mix_in_env_values(): void
    {
        GeneralSetting::set('mail_transport', 'graph');
        GeneralSetting::set('graph_azure_tenant_id', 'azure-tenant');
        GeneralSetting::set('graph_client_id', 'client-id');
        GeneralSetting::set('graph_client_secret', 'geheim');
        config(['services.graph.user_id' => 'mailbox@van-een-ander.nl']);

        /** The mailbox is missing; borrowing someone else's is exactly the mistake. */
        $this->expectException(GraphNotConfigured::class);

        app(TenantMailTransport::class)->make();
    }

    public function test_secret_keys_are_ciphertext_on_disk(): void
    {
        GeneralSetting::set('mail_smtp_password', 'zeer-geheim-wachtwoord');

        $raw = (string) DB::table('general_settings')->where('key', 'mail_smtp_password')->value('value');

        $this->assertStringNotContainsString('zeer-geheim-wachtwoord', $raw);
        $this->assertSame('zeer-geheim-wachtwoord', GeneralSetting::get('mail_smtp_password'));
    }

    public function test_connection_credentials_are_ciphertext_on_disk(): void
    {
        $connection = new IntegrationConnection(['connector' => 'snelstart', 'settings' => []]);
        $connection->storeCredentials(['client_key' => 'zeer-geheime-sleutel']);
        $connection->save();

        $raw = (string) DB::table('integration_connections')->where('id', $connection->id)->value('credentials');

        $this->assertStringNotContainsString('zeer-geheime-sleutel', $raw);
        $this->assertSame('zeer-geheime-sleutel', $connection->fresh()->credentials()['client_key']);
    }

    public function test_the_settings_page_response_contains_no_secret(): void
    {
        GeneralSetting::set('graph_client_secret', 'geheim-dat-niet-mag-lekken');
        GeneralSetting::set('mail_smtp_password', 'wachtwoord-dat-niet-mag-lekken');

        /** The page belongs to the super admin; being an admin is deliberately not enough. */
        $super = User::factory()->create();
        $super->roles()->attach(Role::firstOrCreate(['name' => Role::SUPERADMIN])->id);

        $response = $this->actingAs($super)->get('/technical-management');

        $response->assertOk();
        $this->assertStringNotContainsString('geheim-dat-niet-mag-lekken', $response->getContent());
        $this->assertStringNotContainsString('wachtwoord-dat-niet-mag-lekken', $response->getContent());
    }
}
