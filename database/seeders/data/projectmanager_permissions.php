<?php

/**
 * Rechten voor de rol Projectmanager, als startpunt voor een nieuwe tenant.
 * Namen worden opgezocht; een recht dat in deze installatie niet bestaat wordt overgeslagen.
 */
return [
    'project.create',
    'project.delete',
    'project.manage_financials',
    'project.read',
    'project.update',
    'projectmilestone.create',
    'projectmilestone.delete',
    'projectmilestone.read',
    'projectmilestone.update',
];
