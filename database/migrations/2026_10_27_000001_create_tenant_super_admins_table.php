<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * MajorLabel's own accounts move to the landlord database.
 *
 * They lived only in the customer's own database, and that is the one thing
 * about a customer that is not the customer's: we hand them out, and a customer
 * can neither see nor create them. Keeping them there meant they were gone the
 * moment that database was replaced -- which is exactly what importing an
 * installation a second time does. On the installation this was found on,
 * whoever did the import locked themselves out, and the admin panel showed no
 * super admin either, because it was reading the database that had just been
 * overwritten.
 *
 * From here the landlord database is where they are kept, and the account
 * inside the customer's database is a copy of that, which can be made again at
 * any moment.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('tenant_super_admins', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name')->default('MajorLabel');

            /** One address belongs to one customer, here as everywhere else. */
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();

            $table->index('tenant_id');
        });

        $this->keepWhatIsAlreadyOutThere();
    }

    /**
     * The ones that exist now live in the customers' databases. Copied across
     * with their password, so nobody has to be handed a new one.
     *
     * A customer whose database cannot be opened is skipped and named in the
     * log: a migration must not stop on one broken customer.
     */
    private function keepWhatIsAlreadyOutThere(): void
    {
        if (!Schema::connection('central')->hasTable('tenants')) {
            return;
        }

        foreach (Tenant::on('central')->get() as $tenant) {
            try {
                $found = Tenancy::within($tenant, function () {
                    $role = Role::where('name', Role::SUPERADMIN)->first();

                    if (!$role) {
                        return [];
                    }

                    return $role->users()->withoutGlobalScopes()
                        ->get(['users.name', 'users.email', 'users.password'])
                        ->all();
                });
            } catch (\Throwable $e) {
                Log::warning('Super admins of a tenant could not be read while moving them to the landlord database', [
                    'tenant' => $tenant->getTenantKey(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($found as $user) {
                DB::connection('central')->table('tenant_super_admins')->insertOrIgnore([
                    'tenant_id' => $tenant->getTenantKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_super_admins');
    }
};
