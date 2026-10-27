<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private $permissions = [
            [
                'name' => 'snelstart.send_serviceorder',
                'label' => 'Stuur werkbon naar snelstart',
            ],
            [
                'name' => 'snelstart.get_customers',
                'label' => 'Haal klanten op uit snelstart',
            ],
            [
                'name' => 'snelstart.get_articles',
                'label' => 'Haal artikelen op uit snelstart',
            ],
        ];
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::create([
                'name' => $permission['name'],
                'label' => $permission['label'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
