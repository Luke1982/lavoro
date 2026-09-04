<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProvisionerConnection;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Het aanmaken en opruimen van een tenant, op één plek. De commando's en de
 * worker van het beheerpaneel lopen hier allebei doorheen, zodat er geen twee
 * versies van "een tenant maken" kunnen ontstaan die net iets anders doen.
 *
 * Alles hierin praat als lavoro_provisioner. Dat account hangt in productie aan
 * een eigen Linux-gebruiker, dus dit werkt alleen in een proces dat als die
 * gebruiker draait -- niet in een webverzoek.
 */
class TenantProvisioner
{
    /**
     * Geen constructor die de databaseverbinding omzet.
     *
     * Dat deed hij wel, en daarmee was deze klasse aanmaken genoeg om een heel
     * verzoek of commando om te gooien -- ook als het alleen om een naam ging.
     * Laravel maakt hem bovendien aan voordat handle() draait, dus een commando
     * dat zich eerst wilde verheffen kwam nooit zo ver: de fout viel al bij het
     * aanmaken.
     *
     * Het omzetten gebeurt nu waar het nodig is, in create() en destroy().
     */
    private function asProvisioner(): void
    {
        ProvisionerConnection::use();
        ProvisionerConnection::assertUsable();
    }

    /**
     * Statisch, en met opzet. De naam volgt uit het voorvoegsel en de bedrijfs-
     * naam en heeft geen database nodig -- terwijl deze klasse aanmaken de
     * verbinding wel omzet naar de provisioner. Dat gebeurde per ongeluk bij het
     * valideren van het formulier, waarna het hele webverzoek op een verbinding
     * zat waar de webserver niet bij kan.
     */
    public static function databaseNameFor(string $name): string
    {
        return config('tenancy.database.prefix') . Str::slug($name, '_');
    }

    /**
     * @param  array<int, string>  $modules
     * @return array{tenant: Tenant, password: string}
     */
    public function create(string $name, string $email, string $password = '', string $package = 'starter', array $modules = []): array
    {
        $this->asProvisioner();

        $database = self::databaseNameFor($name);

        if (DB::connection('central')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?', [$database]
        )) {
            throw new Refusal("De database {$database} bestaat al. Kies een andere naam.");
        }

        if (DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->exists()) {
            throw new Refusal("{$email} is al in gebruik bij een andere tenant.");
        }

        $password = $password ?: Str::password(16);

        /**
         * Vanaf hier kan er van alles half klaar komen te staan: een rij zonder
         * database, een database zonder gebruiker, een gebruiker zonder
         * beheerder. Loopt het stuk, dan gaat alles weer weg -- anders blijft er
         * rommel achter die de volgende poging blokkeert ("die naam bestaat al")
         * en die het beheerpaneel op een foutmelding zet, terwijl de melding zelf
         * over een wachtwoord gaat en niet over wat er werkelijk aan de hand is.
         */
        /**
         * Het id vooraf, zodat er bij een fout onomstotelijk vaststaat welke rij
         * van deze aanroep is. Zoeken op databasenaam kan een klant aanwijzen die
         * er al stond.
         */
        $id = (string) Str::uuid();

        try {
            $tenant = Tenant::create([
                'id' => $id,
                'name' => $name,
                'package_key' => $package,
                'modules' => array_values(array_filter($modules)),
                'tenancy_db_name' => $database,
            ]);
        } catch (\Throwable $e) {
            $this->cleanUpAfterFailure(Tenant::on('central')->find($id), $database);

            throw $e;
        }

        /**
         * De bootstrapper wijst de schijven naar deze mappen, maar maakt ze niet
         * aan. Zonder dit mislukt de eerste upload van een nieuwe tenant, en het
         * is een lege map die niemand mist tot dat gebeurt.
         */
        foreach (['public', 'local'] as $disk) {
            File::ensureDirectoryExists(storage_path("tenant-{$tenant->id}/{$disk}"), 0775);
        }

        try {
            tenancy()->initialize($tenant);

            $this->seed($tenant);

            $admin = User::create([
                'name' => 'Beheerder',
                'email' => $email,
                'password' => Hash::make($password),
                'seat_type' => 'office',
            ]);

            /**
             * Beheerder zijn is een rol en geen kolom. Dit stond hier als
             * 'is_admin' => true, een veld dat niet bestaat: het werd stil
             * weggelaten en elke nieuwe tenant begon met een eerste gebruiker
             * die nergens bij mocht.
             */
            $role = Role::where('name', 'admin')->first();

            if (!$role) {
                throw new RuntimeException('De rol admin ontbreekt in de nieuwe database; is het zaaien misgegaan?');
            }

            $admin->roles()->attach($role->id);
        } catch (\Throwable $e) {
            tenancy()->end();

            $this->cleanUpAfterFailure($tenant, $database);

            throw $e;
        } finally {
            tenancy()->end();
        }

        return ['tenant' => $tenant, 'password' => $password];
    }

    /**
     * Draait een mislukte aanmaak terug: de rij, de database, het databaseaccount
     * en de mappen.
     *
     * Zelf niets laten opvallen als het opruimen ook stukloopt. De fout die hier
     * binnenkwam is degene die de gebruiker moet zien; een tweede fout daaroverheen
     * verbergt precies de reden waarom het misging.
     */
    /**
     * Zaait de rollen, de rechten, de fases en het bedrijf.
     *
     * De bibliotheek doet dit zelf ook, met Artisan::call -- en die geeft een
     * exitcode terug waar niemand naar kijkt. Ging het zaaien mis, dan was de
     * klant "aangemaakt" met alleen de rol die uit een migratie komt, en stond
     * er nergens waarom. Precies dat is gebeurd.
     *
     * Hier wordt het opnieuw gedraaid -- alles gaat via firstOrCreate, dus dat
     * mag -- en wél gekeken of het gelukt is. Zo niet, dan gaat de fout mee naar
     * boven en ruimt de aanroeper de halve klant op.
     */
    private function seed(Tenant $tenant): void
    {
        $status = Artisan::call('db:seed', [
            '--class' => TenantDatabaseSeeder::class,
            '--force' => true,
        ]);

        if ($status !== 0) {
            throw new RuntimeException("Het zaaien van {$tenant->name} is mislukt: "
                . trim(Artisan::output()));
        }

        $expected = array_keys(include base_path('database/seeders/data/tenant_roles.php'));
        $missing = array_diff($expected, Role::pluck('name')->all());

        if ($missing !== []) {
            throw new RuntimeException("Na het zaaien ontbreken deze rollen bij {$tenant->name}: "
                . implode(', ', $missing) . '. ' . trim(Artisan::output()));
        }
    }

    private function cleanUpAfterFailure(?Tenant $tenant, string $database): void
    {
        /**
         * Alleen opruimen wat deze aanroep zelf heeft gemaakt.
         *
         * Hier stond een zoekopdracht op databasenaam en een DROP DATABASE op
         * naam. Dat is levensgevaarlijk: mislukt een tweede poging voor een naam
         * die al bestaat, dan gooit het opruimen de klant weg die er al stond en
         * werkte. Weggooien mag nooit iets aanwijzen dat het zelf niet heeft
         * aangemaakt -- dan is een fout bij het aanmaken erger dan de fout zelf.
         */
        if (!$tenant) {
            Log::warning('Aanmaken mislukt voordat er iets bestond; niets op te ruimen', [
                'database' => $database,
            ]);

            return;
        }

        try {
            $this->destroy($tenant);
        } catch (\Throwable $ignored) {
            Log::warning('Opruimen na een mislukte aanmaak lukte niet', [
                'database' => $database,
                'fout' => $ignored->getMessage(),
            ]);
        }
    }

    /**
     * Ruimt alles op wat bij een tenant hoort. De centrale rijen gaan eerst:
     * zonder die kan niemand meer inloggen, ook niet als het halverwege
     * stukloopt.
     */
    public function destroy(Tenant $tenant): void
    {
        $this->asProvisioner();

        $database = $tenant->getInternal('db_name');
        $username = $tenant->tenancy_db_username;
        $files = storage_path('tenant-' . $tenant->id);

        DB::connection('central')->table('user_tenant_lookups')->where('tenant_id', $tenant->id)->delete();

        $tenant->delete();

        $template = config('tenancy.database.template_tenant_connection', 'mysql');

        if ($username) {
            DB::connection($template)->statement("DROP USER IF EXISTS '{$username}'@'%'");
        }

        if ($database) {
            DB::connection($template)->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        if (File::isDirectory($files)) {
            File::deleteDirectory($files);
        }
    }

    /** @return array{users: int, database: ?string, files: string} */
    public function summaryFor(Tenant $tenant): array
    {
        return [
            'users' => DB::connection('central')->table('user_tenant_lookups')
                ->where('tenant_id', $tenant->id)->count(),
            'database' => $tenant->getInternal('db_name'),
            'files' => 'storage/tenant-' . $tenant->id,
        ];
    }
}
