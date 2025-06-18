<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Asset;
use App\Models\Ticket;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceCheck;
use App\Models\ServiceOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Product::factory(30)->create();
        Artisan::call('snelstart:fetch-relaties');
        Asset::factory(100)->create();
        Ticket::factory(100)->create();
        ServiceCheck::factory(100)->create()->each(function ($serviceCheck) {
            $serviceCheck->values()->createMany(
                \App\Models\ServiceCheckValue::factory(5)->make()->toArray()
            );
        });
        ServiceOrder::factory()
        ->count(10)
        ->has(ServiceJob::factory()->count(rand(0, 5)))
        ->create();
    }
}
