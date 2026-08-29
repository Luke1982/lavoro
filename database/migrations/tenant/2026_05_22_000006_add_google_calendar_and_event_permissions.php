<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'google_calendar.connect', 'label' => 'Eigen Google Agenda koppelen'],
        ['name' => 'calendar_grant.manage', 'label' => 'Agenda-toegang van gebruikers beheren'],
        ['name' => 'event.create', 'label' => 'Eigen afspraken aanmaken'],
        ['name' => 'event.create_others', 'label' => 'Afspraken voor anderen aanmaken'],
        ['name' => 'event.update', 'label' => 'Eigen afspraken wijzigen'],
        ['name' => 'event.update_others', 'label' => 'Afspraken van anderen wijzigen'],
        ['name' => 'event.delete', 'label' => 'Eigen afspraken verwijderen'],
        ['name' => 'event.delete_others', 'label' => 'Afspraken van anderen verwijderen'],
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
