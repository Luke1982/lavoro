<?php

namespace Tests\Feature\Tenancy;

use App\Models\Central\UserTenantLookup;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesASecondTenant;
use Tests\TestCase;

/**
 * Twee klanten naast elkaar. Dit is waar het hele project om draait en waar de
 * suite tot nu toe niets over zei: er was één testklant, dus "zien ze elkaar
 * niet" was onbewijsbaar.
 *
 * Elke test hieronder faalt als de scheiding wegvalt. Ze zijn zo geschreven
 * dat ze niet stil goed gaan: eerst wordt gecontroleerd dat het gegeven er in
 * de ene klant wél is, en pas daarna dat het in de andere ontbreekt.
 */
class IsolationTest extends TestCase
{
    use UsesASecondTenant;

    public function test_data_of_one_customer_does_not_exist_in_the_other(): void
    {
        $second = $this->secondTenant();

        $mine = Customer::factory()->create(['name' => 'Klant van de eerste']);

        $theirs = $this->asTenant($second, fn () => Customer::factory()->create(['name' => 'Klant van de tweede']));

        /** Eerst bewijzen dat het gegeven bestaat, anders zegt de rest niets. */
        $this->assertTrue(Customer::whereKey($mine->id)->exists());

        $this->assertFalse(
            $this->asTenant($second, fn () => Customer::where('name', 'Klant van de eerste')->exists()),
            'De tweede klant ziet de gegevens van de eerste.',
        );

        $this->assertFalse(
            Customer::where('name', 'Klant van de tweede')->exists(),
            'De eerste klant ziet de gegevens van de tweede.',
        );

        $this->assertSame($theirs->name, $this->asTenant($second, fn () => Customer::find($theirs->id)?->name));
    }

    /**
     * Record-id's lopen per klant op, dus id 1 bestaat in allebei. Dat is
     * precies waarom een id alleen nooit genoeg is om iets aan te wijzen.
     */
    public function test_the_same_id_points_at_a_different_record_in_each_customer(): void
    {
        $second = $this->secondTenant();

        /** Hetzelfde id afdwingen: anders hangt de test af van waar de tellers staan. */
        $id = 987654;

        Customer::factory()->create(['id' => $id, 'name' => 'Van de eerste']);
        $this->asTenant($second, fn () => Customer::factory()->create(['id' => $id, 'name' => 'Van de tweede']));

        $this->assertSame('Van de eerste', Customer::findOrFail($id)->name);

        $this->assertSame(
            'Van de tweede',
            $this->asTenant($second, fn () => Customer::findOrFail($id)->name),
            'Hetzelfde id hoort in elke klant een ander record te zijn.',
        );
    }

    public function test_each_customer_writes_to_its_own_database(): void
    {
        $second = $this->secondTenant();

        $first_database = DB::connection('tenant')->getDatabaseName();
        $second_database = $this->asTenant($second, fn () => DB::connection('tenant')->getDatabaseName());

        $this->assertNotSame($first_database, $second_database);
        $this->assertStringContainsString('tenant', $second_database);
    }

    /**
     * De cache is per klant voorzien van een eigen aanhef. Gaat dat mis, dan
     * is het geen gemiste cache maar het gegeven van een ander bedrijf -- en
     * er zit onder andere een SnelStart-token in.
     *
     * Er wordt gekeken naar de aanhef en naar wat er onder water komt te
     * staan, en niet naar teruglezen na een wissel: de cachetabel valt binnen
     * de testtransactie en de verbinding wordt bij een wissel opnieuw
     * opgezet, dus dat zou het testopzet meten in plaats van de afscherming.
     */
    public function test_the_cache_is_not_shared_between_customers(): void
    {
        $second = $this->secondTenant();

        $first_prefix = config('cache.prefix');
        $second_prefix = $this->asTenant($second, fn () => config('cache.prefix'));

        $this->assertNotSame($first_prefix, $second_prefix, 'Beide klanten schrijven onder dezelfde aanhef.');
        $this->assertStringContainsString((string) $this->firstTenant()->getTenantKey(), $first_prefix);
        $this->assertStringContainsString((string) $second->getTenantKey(), $second_prefix);

        /** Wat er werkelijk in de tabel belandt, moet dus ook verschillen. */
        Cache::put('gedeelde-sleutel', 'van de eerste', 60);

        $this->assertSame('van de eerste', Cache::get('gedeelde-sleutel'));

        $this->assertNull(
            $this->asTenant($second, fn () => Cache::get('gedeelde-sleutel')),
            'De tweede klant leest de cache van de eerste.',
        );

        $stored = DB::connection('central')->table('cache')
            ->where('key', 'like', '%gedeelde-sleutel')
            ->pluck('key');

        $this->assertTrue(
            $stored->every(fn (string $key) => str_starts_with($key, $first_prefix)),
            'Er staat een sleutel in de cache zonder de aanhef van deze klant: ' . $stored->implode(', '),
        );
    }

    /** Bestanden van de een horen niet in de map van de ander te landen. */
    public function test_uploads_land_in_a_folder_of_their_own(): void
    {
        $second = $this->secondTenant();

        $first_root = Storage::disk('public')->path('');
        $second_root = $this->asTenant($second, fn () => Storage::disk('public')->path(''));

        $this->assertNotSame($first_root, $second_root);
        $this->assertStringContainsString((string) $this->firstTenant()->getTenantKey(), $first_root);
        $this->assertStringContainsString((string) $second->getTenantKey(), $second_root);
    }

    /**
     * Het e-mailadres wijst bij het inloggen de klant aan, dus dezelfde
     * gebruiker bij twee klanten kan niet.
     */
    public function test_an_email_address_belongs_to_one_customer_only(): void
    {
        $second = $this->secondTenant();
        $email = 'gedeeld-' . uniqid() . '@example.com';

        User::factory()->create(['email' => $email]);

        $this->assertSame(
            $this->firstTenant()->getTenantKey(),
            UserTenantLookup::on('central')->find($email)?->tenant_id,
        );

        $this->expectException(\RuntimeException::class);

        $this->asTenant($second, fn () => User::factory()->create(['email' => $email]));
    }

    /**
     * Het spoor van wat er gebeurt hoort in de database van de klant zelf te
     * staan. Belandt het bij de ander, dan leest die de geschiedenis van een
     * bedrijf dat hij niet kent -- en dat merkt niemand, want er gaat niets
     * stuk.
     */
    public function test_the_audit_trail_stays_with_the_customer_it_belongs_to(): void
    {
        $second = $this->secondTenant();

        $before = DB::connection('tenant')->table('activities')->count();

        Customer::factory()->create(['name' => 'Spoortest eerste']);

        $this->assertGreaterThan(
            $before,
            DB::connection('tenant')->table('activities')->count(),
            'Er hoort iets in het spoor van de eerste klant bij te komen.',
        );

        $this->assertSame(
            0,
            $this->asTenant($second, fn () => DB::connection('tenant')->table('activities')->count()),
            'Het spoor van de eerste klant belandde bij de tweede.',
        );
    }
}
