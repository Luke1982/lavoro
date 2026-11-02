<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permission = [
        'name' => 'asset.set_serial.relevant.serviceorder',
        'label' => 'Serienummer invullen op machines van open werkbonnen',
    ];

    public function up(): void
    {
        if (!Permission::where('name', $this->permission['name'])->exists()) {
            Permission::create($this->permission);
        }
    }

    public function down(): void
    {
        Permission::where('name', $this->permission['name'])->delete();
    }
};
