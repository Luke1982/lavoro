<?php

/**
 * The roles a new tenant is given, with the file holding their permissions.
 * This is the only list: TenantDatabaseSeeder walks it and looks up
 * database/seeders/data/{slug}_permissions.php per role.
 *
 * Adding a role is a line here and a file next to it, nowhere else.
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
