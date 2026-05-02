<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        DB::table('permissions')->insert([
            ['name' => 'productrelation.read',   'label' => 'Productrelaties bekijken',    'created_at' => $now, 'updated_at' => $now],
            ['name' => 'productrelation.create',  'label' => 'Productrelaties aanmaken',    'created_at' => $now, 'updated_at' => $now],
            ['name' => 'productrelation.update',  'label' => 'Productrelaties bewerken',    'created_at' => $now, 'updated_at' => $now],
            ['name' => 'productrelation.delete',  'label' => 'Productrelaties verwijderen', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'productable.read',        'label' => 'Productkoppelingen bekijken',    'created_at' => $now, 'updated_at' => $now],
            ['name' => 'productable.create',      'label' => 'Productkoppelingen aanmaken',    'created_at' => $now, 'updated_at' => $now],
            ['name' => 'productable.delete',      'label' => 'Productkoppelingen verwijderen', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'assetrelation.create',    'label' => 'Asset-relaties aanmaken',     'created_at' => $now, 'updated_at' => $now],
            ['name' => 'assetrelation.delete',    'label' => 'Asset-relaties verwijderen',  'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'productrelation.read',
            'productrelation.create',
            'productrelation.update',
            'productrelation.delete',
            'productable.read',
            'productable.create',
            'productable.delete',
            'assetrelation.create',
            'assetrelation.delete',
        ])->delete();
    }
};
