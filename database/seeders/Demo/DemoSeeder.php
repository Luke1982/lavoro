<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
    /**
     * @param  (Closure(string): void)|null  $progress  told what is happening, for a command to show
     */
    public function __construct(private ?CarbonImmutable $now = null, private ?Closure $progress = null) {}

    public function run(): void
    {
        $context = new DemoContext($this->now);

        Model::unguarded(function () use ($context) {
            $this->phase('team', fn () => (new TeamSeeder($context))->run(),
                fn () => count($context->users) . ' people');
            $this->phase('catalogue', fn () => (new CatalogueSeeder($context))->run(),
                fn () => count($context->types) . ' product types, ' . collect($context->products)->flatten()->count() . ' products');
            $this->phase('customers', fn () => (new CustomerSeeder($context))->run(),
                fn () => count($context->customers) . ' customers, '
                    . collect($context->customers)->sum(fn (array $entry) => collect($entry['sites'])->sum(fn (array $site) => count($site['assets'])))
                    . ' machines');
            $this->phase('planning, orders and tickets', fn () => (new WorkSeeder($context))->run());
        });
    }

    /**
     * One transaction per phase. Row by row, every insert is a commit of its
     * own, and on MariaDB every commit waits for the disk: the demo took ninety
     * seconds on a laptop and many minutes on the server, without a word.
     */
    private function phase(string $name, Closure $work, ?Closure $summary = null): void
    {
        $started = microtime(true);
        $this->tell("{$name}...");

        DB::connection('tenant')->transaction($work);

        $this->tell(sprintf('%s: %s(%.0f s)', $name, $summary ? $summary() . ' ' : '', microtime(true) - $started));
    }

    private function tell(string $message): void
    {
        if ($this->progress) {
            ($this->progress)($message);
        }
    }
}
