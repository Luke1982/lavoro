<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'serviceorder.export_pdf',
            'label' => 'Mag werkbon PDF exporteren',
        ],
        [
            'name' => 'serviceorder.email_pdf',
            'label' => 'Mag werkbon PDF e-mailen',
        ],
        [
            'name' => 'serviceorder.email_pdf_with_jobs',
            'label' => 'Mag werkbon PDF met periodieke controles e-mailen',
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
