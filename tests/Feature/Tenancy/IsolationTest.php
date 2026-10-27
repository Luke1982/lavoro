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
 * Two customers side by side. This is what the whole project is about and what
 * the suite said nothing about so far: there was one test customer, so "they do
 * not see each other" was unprovable.
 *
 * Every test below fails when the separation falls away. They are written so
 * they cannot pass quietly: first it is checked that the data is there in the
 * one customer, and only then that it is missing in the other.
 */
class IsolationTest extends TestCase
{
    use UsesASecondTenant;

    public function test_data_of_one_customer_does_not_exist_in_the_other(): void
    {
        $second = $this->secondTenant();

        $mine = Customer::factory()->create(['name' => 'Klant van de eerste']);

        $theirs = $this->asTenant($second, fn () => Customer::factory()->create(['name' => 'Klant van de tweede']));

        /** First prove the data exists, otherwise the rest says nothing. */
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
     * Record ids count up per customer, so id 1 exists in both. That is exactly
     * why an id alone is never enough to point at something.
     */
    public function test_the_same_id_points_at_a_different_record_in_each_customer(): void
    {
        $second = $this->secondTenant();

        /** Forcing the same id: otherwise the test depends on where the counters stand. */
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
     * The cache is given a prefix of its own per customer. If that goes wrong
     * it is not a cache miss but another company's data -- and there is a
     * SnelStart token among it.
     *
     * It looks at the prefix and at what ends up underneath, and not at reading
     * back after a switch: the cache table falls inside the test transaction and
     * the connection is rebuilt on a switch, so that would measure the test
     * setup instead of the separation.
     */
    public function test_the_cache_is_not_shared_between_customers(): void
    {
        $second = $this->secondTenant();

        $first_prefix = config('cache.prefix');
        $second_prefix = $this->asTenant($second, fn () => config('cache.prefix'));

        $this->assertNotSame($first_prefix, $second_prefix, 'Beide klanten schrijven onder dezelfde aanhef.');
        $this->assertStringContainsString((string) $this->firstTenant()->getTenantKey(), $first_prefix);
        $this->assertStringContainsString((string) $second->getTenantKey(), $second_prefix);

        /** What really lands in the table therefore has to differ too. */
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

    /** One customer's files should not land in the other's folder. */
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
     * The email address points at the customer when logging in, so the same
     * user at two customers cannot be.
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
     * The trail of what happens belongs in the customer's own database. If it
     * lands at the other one, they read the history of a company they do not
     * know -- and nobody notices, because nothing breaks.
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
