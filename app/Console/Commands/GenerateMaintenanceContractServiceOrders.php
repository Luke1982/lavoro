<?php

namespace App\Console\Commands;

use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Console\Command;

class GenerateMaintenanceContractServiceOrders extends Command
{
    protected $signature = 'maintenancecontracts:generate-serviceorders';
    protected $description = 'Genereert werkbonnen voor machines die aan de beurt zijn volgens hun onderhoudscontract.';

    public function handle(MaintenanceContractServiceOrderGenerator $generator)
    {
        $created = $generator->generateAllDue();

        $this->info(count($created) . ' werkbon(nen) gegenereerd.');
    }
}
