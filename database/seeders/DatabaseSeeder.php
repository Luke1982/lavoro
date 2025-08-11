<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Asset;
use App\Models\Ticket;
use App\Models\Product;
use App\Models\Material;
use App\Models\EventType;
use App\Models\ServiceJob;
use App\Models\MaterialRole;
use App\Models\ServiceCheck;
use App\Models\ServiceOrder;
use Illuminate\Database\Seeder;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use Illuminate\Database\Eloquent\Model;
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
        Model::withoutEvents(function () {
            ServiceOrder::factory()
            ->count(10)
            ->has(ServiceJob::factory()->count(rand(0, 5)))
            ->create();
        });
        Artisan::call('snelstart:fetch-artikelen');
        MaterialCategory::factory(10)->create();
        MaterialUsageUnit::factory(10)->create();
        MaterialRole::factory(4)->create();
        EventType::factory(5)->create();
    }
}
