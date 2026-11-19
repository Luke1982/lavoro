<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'quote.read', 'label' => 'Offertes bekijken'],
        ['name' => 'quote.create', 'label' => 'Offertes aanmaken'],
        ['name' => 'quote.update', 'label' => 'Offertes wijzigen en versturen'],
        ['name' => 'quote.delete', 'label' => 'Offertes verwijderen'],
        ['name' => 'quote.see_margins', 'label' => 'Inkoopprijzen en marges op offertes zien'],
        ['name' => 'quote.manage_defaults', 'label' => 'Standaardmaterialen bij producten instellen'],
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
