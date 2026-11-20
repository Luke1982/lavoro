<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Capability;
use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\Contracts\ReceivesSalesDocuments;
use App\Domain\Integrations\Contracts\ReceivesWebhooks;
use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Data\LedgerAccountData;
use App\Domain\Integrations\Data\MaterialData;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Domain\Signals\Integrations\ServiceOrderSentToAdministration;
use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What every connector promises, checked for every connector there is -- a new
 * one included, the moment it is added to Connectors. Each failure says which
 * connector broke which promise.
 */
class ConnectorContractTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Stored on every connection. Renaming one orphans every customer's
     * connection to that system, so a change here has to be deliberate.
     */
    private const KEYS = ['snelstart', 'eboekhouden', 'businesscentral', 'exactonline', 'afas', 'moneybird'];

    /** @return array<string, array{0: class-string<Connector>}> */
    public static function connectors(): array
    {
        $connectors = [];

        foreach (Connectors::all() as $key => $class) {
            $connectors[$key] = [$class];
        }

        return $connectors;
    }

    public function test_the_stored_keys_do_not_change(): void
    {
        $this->assertSame(self::KEYS, array_keys(Connectors::all()));
    }

    /** @param  class-string<Connector>  $connector */
    #[DataProvider('connectors')]
    public function test_it_describes_itself(string $connector): void
    {
        $this->assertNotSame('', $connector::label(), $connector . ' has no label');
        $this->assertNotSame('', $connector::description(), $connector . ' has no description');
        $this->assertTrue($connector::connectsWithOAuth() || $connector::credentialFields() !== [], $connector . ' asks for no credentials and has no login either');
        $this->assertNotSame([], $connector::capabilities(), $connector . ' can do nothing');
        $this->assertJson(json_encode($connector::describe(), JSON_THROW_ON_ERROR));
    }

    /** @param  class-string<Connector>  $connector */
    #[DataProvider('connectors')]
    public function test_its_fields_have_distinct_keys(string $connector): void
    {
        $keys = array_map(fn (Field $field) => $field->key, [...$connector::credentialFields(), ...$connector::optionFields()]);

        $this->assertSame(array_unique($keys), $keys, $connector . ' uses a field key twice');
    }

    /** @param  class-string<Connector>  $connector */
    #[DataProvider('connectors')]
    public function test_it_only_carries_fields_lavoro_knows_and_always_a_name(string $connector): void
    {
        $this->assertSame([], array_diff($connector::customerFields(), CustomerData::FIELDS), $connector . ' carries unknown customer fields');
        $this->assertSame([], array_diff($connector::materialFields(), MaterialData::FIELDS), $connector . ' carries unknown material fields');
        $this->assertSame([], array_diff($connector::ledgerAccountFields(), LedgerAccountData::FIELDS), $connector . ' carries unknown ledger account fields');

        if ($connector::can(Capability::CustomersRead)) {
            $this->assertContains('name', $connector::customerFields(), $connector . ' pulls customers without a name, which are all skipped');
        }

        if ($connector::can(Capability::MaterialsRead)) {
            $this->assertContains('name', $connector::materialFields(), $connector . ' pulls materials without a name, which are all skipped');
        }

        if ($connector::can(Capability::LedgerAccountsRead)) {
            $this->assertContains('name', $connector::ledgerAccountFields(), $connector . ' pulls ledger accounts without a name, which are all skipped');
        }
    }

    /** @param  class-string<Connector>  $connector */
    #[DataProvider('connectors')]
    public function test_what_it_offers_fits_what_it_can(string $connector): void
    {
        if (is_subclass_of($connector, ReceivesSalesDocuments::class)) {
            $this->assertNotSame([], $connector::documentForms(), $connector . ' takes werkbonnen in no form at all');
        }

        if (is_subclass_of($connector, ReceivesWebhooks::class)) {
            foreach ($connector::webhookAreas() as $area) {
                $read = Capability::read($area);
                $this->assertTrue($read && $connector::can($read), $connector . ' sends notices for ' . $area->value . ', which it cannot be pulled for');
            }
        }
    }

    /**
     * What a new connection starts with must pass the same validation as what
     * a person saves, or connecting a system fails on its own defaults.
     *
     * @param  class-string<Connector>  $connector
     */
    #[DataProvider('connectors')]
    public function test_its_default_settings_pass_validation(string $connector): void
    {
        $credentials = [];

        foreach ($connector::credentialFields() as $field) {
            $credentials[$field->key] = match (true) {
                in_array('uuid', $field->rules, true) => (string) Str::uuid(),
                str_contains(implode(' ', $field->rules), '\\d') => '12345',
                default => 'voorbeeld',
            };
        }

        $defaults = IntegrationConnection::defaultSettings($connector::key());

        $this->actingAs($this->userWith('integration.manage'))
            ->post('/integrations', ['connector' => $connector::key(), 'credentials' => $credentials, 'areas' => $defaults])
            ->assertSessionHasNoErrors();
    }

    public function test_every_capability_has_its_contract_and_every_synced_area_its_rules(): void
    {
        foreach (Capability::cases() as $capability) {
            $this->assertTrue(interface_exists($capability->contract()), $capability->value . ' points at no interface');
        }

        foreach (Area::cases() as $area) {
            if (Capability::read($area)) {
                $this->assertSame($area, Synchronizer::mapping($area)->area(), $area->value . ' can be pulled but has no mapping');
                $this->assertSame($area, Capability::read($area)->area());
            }

            if ($write = Capability::write($area)) {
                $this->assertSame($area, $write->area());
            }
        }
    }

    /** The activity trail and the assistant find werkbonnen sent by this key: never rename it. */
    public function test_the_signal_key_stays(): void
    {
        $this->assertSame('integration.serviceorder_sent', ServiceOrderSentToAdministration::key());
    }

    /**
     * Every outside system goes through the funnel: no code elsewhere talks to
     * one directly, and no model gets a "<system>_id" column again.
     */
    public function test_nothing_outside_the_funnel_talks_to_a_bookkeeping_system(): void
    {
        $hosts = ['snelstart.nl', 'businesscentral.dynamics.com', 'e-boekhouden.nl', 'exactonline.nl', 'moneybird.com', 'afas.online'];
        $allowed = ['Domain/Integrations/', 'Services/EBoekhouden/'];
        $offenders = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $relative = str_replace(app_path() . '/', '', $file->getPathname());
            $source = $file->getContents();

            foreach ($hosts as $host) {
                if (str_contains($source, $host) && !collect($allowed)->contains(fn (string $dir) => str_starts_with($relative, $dir))) {
                    $offenders[] = $relative . ' mentions ' . $host;
                }
            }

            if (preg_match('/\b(snelstart|eboekhouden|businesscentral|exact|moneybird|afas)_id\b/', $source)) {
                $offenders[] = $relative . ' uses a <system>_id column; link through integrationables instead';
            }
        }

        $this->assertSame([], $offenders);
    }
}
