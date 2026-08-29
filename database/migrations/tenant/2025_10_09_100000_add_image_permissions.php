<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'image.upload', 'label' => 'Afbeeldingen uploaden'],
        ['name' => 'image.see', 'label' => 'Afbeeldingen bekijken'],
        ['name' => 'image.delete', 'label' => 'Afbeeldingen verwijderen'],
        ['name' => 'image.update', 'label' => 'Afbeeldingen titel wijzigen'],
        ['name' => 'image.edit', 'label' => 'Afbeeldingen annoteren'],
    ];

    public function up(): void
    {
        DB::table('permissions')->insert($this->permissions);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_column($this->permissions, 'name'))->delete();
    }
};
