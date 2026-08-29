<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Rollen toekennen zat vastgeklonken aan admin. Het is een eigen recht omdat
 * wie rollen uitdeelt indirect alle rechten uitdeelt: dat hoort los te staan
 * van het gewone bewerken van een gebruiker.
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'user.assign_roles', 'label' => 'Rollen aan gebruikers toekennen'],
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
