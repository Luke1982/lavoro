<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Bevestigen staat er niet bij. Wie de aankondiging krijgt mag hem bevestigen
 * en niemand anders, en dat is een vraag over de ontvangerrij, niet over een rol.
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'internalannouncement.read', 'label' => 'Aankondigingen bekijken'],
        ['name' => 'internalannouncement.create', 'label' => 'Aankondigingen aanmaken'],
        ['name' => 'internalannouncement.update', 'label' => 'Aankondigingen wijzigen'],
        ['name' => 'internalannouncement.delete', 'label' => 'Aankondigingen verwijderen'],
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
