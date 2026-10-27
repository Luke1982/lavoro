<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Writing to a customer is something else than updating an incident, so it is a
 * permission of its own: post goes out in the company's name, with a link
 * inside attached to it.
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
