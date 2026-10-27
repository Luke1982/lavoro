<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Acknowledging is not among them. Whoever gets the announcement may
 * acknowledge it and nobody else, and that is a question about the recipient
 * row, not about a role.
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
