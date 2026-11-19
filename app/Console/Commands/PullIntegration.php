<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Models\IntegrationConnection;
use Illuminate\Console\Command;

/**
 * The same pull as the button, for the active customer: run it inside
 * tenants:run, or from tinker after initialising a customer.
 */
class PullIntegration extends Command
{
    protected $signature = 'integrations:pull {connector : snelstart, eboekhouden, ...} {area : customers of materials}';

    protected $description = 'Haalt klanten of materialen op uit een gekoppeld systeem van de actieve klant.';

    public function handle(Synchronizer $synchronizer): int
    {
        $connection = IntegrationConnection::where('connector', $this->argument('connector'))->first();
        $area = Area::tryFrom((string) $this->argument('area'));

        if (!$connection || !$area) {
            $this->error('Geen koppeling met dat systeem, of geen bekend onderdeel (customers, materials).');

            return self::FAILURE;
        }

        $run = $synchronizer->pull($connection, $area);
        $this->info($run->summary() . '.');

        return self::SUCCESS;
    }
}
