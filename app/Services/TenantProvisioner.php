<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProvisionerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
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
     * Verheft zichzelf niet: dit draait in de worker, en die hoort al als
     * lavoro_provisioner te draaien. Kan hij het niet, dan is dat een fout
     * die op de aanvraag hoort te belanden en niet stil weg mag vallen.
     */
    public function __construct()
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
        $database = self::databaseNameFor($name);

        if (DB::connection('central')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?', [$database]
        )) {
            throw new RuntimeException("De database {$database} bestaat al. Kies een andere naam.");
        }

        if (DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->exists()) {
            throw new RuntimeException("{$email} is al in gebruik bij een andere tenant.");
        }

        $password = $password ?: Str::password(16);

        $tenant = Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'package_key' => $package,
            'modules' => array_values(array_filter($modules)),
            'tenancy_db_name' => $database,
        ]);

        /**
         * De bootstrapper wijst de schijven naar deze mappen, maar maakt ze niet
         * aan. Zonder dit mislukt de eerste upload van een nieuwe tenant, en het
         * is een lege map die niemand mist tot dat gebeurt.
         */
        foreach (['public', 'local'] as $disk) {
            File::ensureDirectoryExists(storage_path("tenant-{$tenant->id}/{$disk}"), 0775);
        }

        tenancy()->initialize($tenant);

        try {
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
        } finally {
            tenancy()->end();
        }

        return ['tenant' => $tenant, 'password' => $password];
    }

    /**
     * Ruimt alles op wat bij een tenant hoort. De centrale rijen gaan eerst:
     * zonder die kan niemand meer inloggen, ook niet als het halverwege
     * stukloopt.
     */
    public function destroy(Tenant $tenant): void
    {
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
