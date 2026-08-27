<?php

/**
 * Rechten voor de rol Projectleider, als startpunt voor een nieuwe tenant.
 * Namen worden opgezocht; een recht dat in deze installatie niet bestaat wordt overgeslagen.
 */
return [
    'project.read',
    'project.update',
    'projectmilestone.create',
    'projectmilestone.delete',
    'projectmilestone.read',
    'projectmilestone.update',
    'projects.lead',
];
