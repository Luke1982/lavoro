<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:create
        {name : De bedrijfsnaam}
        {email : Het e-mailadres van de eerste beheerder}
        {--admin-password= : Wachtwoord; leeg laten genereert er een}
        {--package=starter}
        {--modules= : Kommagescheiden}';

    protected $description = 'Maakt een nieuwe tenant met een eigen database, MySQL-login en beheerder';

    public function handle(): int
    {
        $this->runAsProvisioner();

        $name = $this->argument('name');
        $email = $this->argument('email');
        $database = config('tenancy.database.prefix') . Str::slug($name, '_');

        if (DB::connection('central')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?', [$database]
        )) {
            $this->error("De database {$database} bestaat al. Kies een andere naam.");

            return self::FAILURE;
        }

        if (DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->exists()) {
            $this->error("{$email} is al in gebruik bij een andere tenant.");

            return self::FAILURE;
        }

        $password = $this->option('admin-password') ?: Str::password(16);

        $tenant = Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'package_key' => $this->option('package'),
            'modules' => array_values(array_filter(explode(',', (string) $this->option('modules')))),
            'tenancy_db_name' => $database,
        ]);

        tenancy()->initialize($tenant);

        $admin = User::create([
            'name' => 'Beheerder',
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
        ]);

        tenancy()->end();

        $this->info("Tenant aangemaakt: {$tenant->id}");
        $this->line('  database:  ' . $database);
        $this->line('  beheerder: ' . $admin->email);
        $this->line('  wachtwoord: ' . $password);

        return self::SUCCESS;
    }
}
