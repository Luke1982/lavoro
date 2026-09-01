<?php

namespace Tests\Feature;

use App\Exceptions\GraphNotConfigured;
use App\Exceptions\MailNotConfigured;
use App\Exceptions\SnelStartNotConfigured;
use App\Models\GeneralSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\SnelStartClient;
use App\Support\TenantMailTransport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * De regel achter taak 32: sleutels zijn per tenant of ze zijn er niet. Er is
 * geen terugval op de .env, want post uit andermans mailbox of boekingen in
 * andermans administratie zijn erger dan een nette weigering.
 */
class IntegrationCredentialsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_snelstart_without_credentials_throws_rather_than_falling_back(): void
    {
        config(['services.snelstart.client_key' => 'global-sleutel-die-niemand-mag-gebruiken']);

        $this->expectException(SnelStartNotConfigured::class);

        new SnelStartClient;
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

        /** De mailbox ontbreekt; die van een ander lenen is precies de fout. */
        $this->expectException(GraphNotConfigured::class);

        app(TenantMailTransport::class)->make();
    }

    public function test_secret_keys_are_ciphertext_on_disk(): void
    {
        GeneralSetting::set('snelstart_client_key', 'zeer-geheime-sleutel');

        $raw = (string) DB::table('general_settings')->where('key', 'snelstart_client_key')->value('value');

        $this->assertStringNotContainsString('zeer-geheime-sleutel', $raw);
        $this->assertSame('zeer-geheime-sleutel', GeneralSetting::get('snelstart_client_key'));
    }

    public function test_the_settings_page_response_contains_no_secret(): void
    {
        GeneralSetting::set('graph_client_secret', 'geheim-dat-niet-mag-lekken');
        GeneralSetting::set('mail_smtp_password', 'wachtwoord-dat-niet-mag-lekken');

        /** De pagina is van de superbeheerder; beheerder zijn is bewust niet genoeg. */
        $super = User::factory()->create();
        $super->roles()->attach(Role::firstOrCreate(['name' => Role::SUPERADMIN])->id);

        $response = $this->actingAs($super)->get('/technical-management');

        $response->assertOk();
        $this->assertStringNotContainsString('geheim-dat-niet-mag-lekken', $response->getContent());
        $this->assertStringNotContainsString('wachtwoord-dat-niet-mag-lekken', $response->getContent());
    }
}
