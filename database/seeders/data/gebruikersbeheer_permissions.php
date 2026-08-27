<?php

/**
 * Rechten voor de rol Gebruikersbeheer, als startpunt voor een nieuwe tenant.
 * Namen worden opgezocht; een recht dat in deze installatie niet bestaat wordt overgeslagen.
 */
return [
    'calendar_grant.manage',
    'roster.manage_all',
    'user.assign_roles',
    'user.create',
    'user.delete',
    'user.read',
    'user.restore',
    'user.update',
];
