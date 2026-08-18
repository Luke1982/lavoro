<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Een klant aanschrijven is iets anders dan een storing bijwerken, dus het is een
 * eigen recht: er gaat post de deur uit op naam van het bedrijf, met een link
 * naar binnen eraan vast.
 */
return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'ticket.request_customer_info',
            'label' => 'Klant om aanvullende informatie vragen',
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
