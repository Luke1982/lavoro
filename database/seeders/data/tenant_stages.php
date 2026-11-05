<?php

/**
 * The work order stages a new tenant is given, in their order. Each one carries
 * the flag the application reasons with; the doctor wants a stage for every flag.
 * This is the only list: TenantDatabaseSeeder walks it for a new tenant, and
 * tenant:adapt-import fills in the ones an imported installation lacks.
 */
return [
    ['name' => 'Nieuw', 'order' => 1, 'is_plannable_state' => true],
    ['name' => 'Planning geannuleerd', 'order' => 2, 'is_plannable_state' => true, 'is_planning_cancelled_state' => true],
    ['name' => 'Gepland', 'order' => 3, 'is_planned_state' => true],
    ['name' => 'Niet afgerond', 'order' => 4, 'is_incomplete_state' => true],
    ['name' => 'Gesloten', 'order' => 5, 'is_closed_state' => true],
    ['name' => 'Gefactureerd', 'order' => 6, 'is_invoiced_state' => true],
];
