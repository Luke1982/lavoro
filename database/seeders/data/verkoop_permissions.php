<?php

/**
 * Rechten voor de rol Verkoop, als startpunt voor een nieuwe tenant.
 * Namen worden opgezocht; een recht dat in deze installatie niet bestaat wordt overgeslagen.
 */
return [
    'customer.read',
    'product.read',
    'product.view_prices',
    'productable.read',
    'productrelation.read',
];
