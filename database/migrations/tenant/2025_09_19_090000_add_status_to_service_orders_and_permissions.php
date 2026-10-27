<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;

return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'serviceorder.close',
            'label' => 'Mag werkbon sluiten',
        ],
        [
            'name' => 'serviceorder.reopen',
            'label' => 'Mag werkbon heropenen',
        ],
    ];

    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('status')->default('open')->nullable()->after('sent_to_customer');
        });

        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
