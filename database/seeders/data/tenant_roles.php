<?php

/**
 * De rollen die een nieuwe tenant meekrijgt, met het bestand waar hun rechten in
 * staan. Dit is de enige lijst: TenantDatabaseSeeder loopt hem af en zoekt per
 * rol database/seeders/data/{slug}_permissions.php op.
 *
 * Een rol toevoegen is hier een regel en een bestand ernaast, nergens anders.
 */
return [
    'admin' => 'admin',
    'Monteur' => 'monteur',
    'Binnendienst' => 'binnendienst',
    'Planner' => 'planner',
    'Administratie' => 'administratie',
    'Verkoop' => 'verkoop',
    'Projectleider' => 'projectleider',
    'Projectmanager' => 'projectmanager',
    'Gebruikersbeheer' => 'gebruikersbeheer',
    'HR' => 'hr',
];
