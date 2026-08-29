<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;

return new class extends Migration
{
    private array $permissions = [
        [
            'name' => 'product.read.relevant.serviceorder',
            'label' => 'Producten zien die relevant zijn voor open werkbonnen',
        ],
        [
            'name' => 'asset.read.relevant.serviceorder',
            'label' => 'Machines zien die relevant zijn voor open werkbonnen',
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
