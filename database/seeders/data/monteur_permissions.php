<?php

/**
 * Rechten voor de rol Monteur, als startpunt voor een nieuwe tenant.
 * Namen worden opgezocht; een recht dat in deze installatie niet bestaat wordt overgeslagen.
 */
return [
    'asset.read.relevant.serviceorder',
    'dashboard.see_events',
    'document.see',
    'event.execute',
    'event.read',
    'freeformmaterial.create',
    'freeformmaterial.delete',
    'freeformmaterial.read',
    'freeformmaterial.update',
    'google_calendar.connect',
    'image.edit',
    'image.see',
    'image.update',
    'image.upload',
    'location.track',
    'materiable.delete.serviceorder',
    'materiable.read.serviceorder',
    'materiable.update.serviceorder',
    'material.create',
    'product.read.relevant.serviceorder',
    'project.read',
    'servicejob.export_pdf',
    'servicejob.mail_pdf',
    'servicejob.read',
    'servicejob.update',
    'serviceorder.close',
    'serviceorder.mark_partially_complete',
    'serviceorder.read_own',
    'serviceordertaskinstance.open_close',
    'serviceordertaskinstance.read',
    'ticket.change_status',
    'ticket.read',
];
