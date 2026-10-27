<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'servicejob.export_pdf',
            'label' => 'Mag periodieke controle PDF exporteren',
        ],
        [
            'name' => 'servicejob.mail_pdf',
            'label' => 'Mag periodieke controle PDF e-mailen',
        ],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
