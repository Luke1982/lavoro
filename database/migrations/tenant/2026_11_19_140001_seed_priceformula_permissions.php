<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'priceformula.read', 'label' => 'Prijsformules bekijken'],
        ['name' => 'priceformula.create', 'label' => 'Prijsformules aanmaken'],
        ['name' => 'priceformula.update', 'label' => 'Prijsformules wijzigen en contracten bijwerken'],
        ['name' => 'priceformula.delete', 'label' => 'Prijsformules verwijderen'],
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
