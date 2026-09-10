<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Fills the demo tenant: a team, a catalogue, customers with installations,
 * five weeks of planning, open orders and tickets.
 *
 * Runs inside a tenant; DemoInstaller opens it. Also runnable by hand against an
 * existing tenant with tenants:seed --class="Database\Seeders\Demo\DemoSeeder",
 * on an empty database -- it creates, it does not reconcile.
 */
class DemoSeeder extends Seeder
{
    public function __construct(private ?CarbonImmutable $now = null) {}

    public function run(): void
    {
        $context = new DemoContext($this->now);

        Model::unguarded(function () use ($context) {
            (new TeamSeeder($context))->run();
            (new CatalogueSeeder($context))->run();
            (new CustomerSeeder($context))->run();
            (new WorkSeeder($context))->run();
        });
    }
}
