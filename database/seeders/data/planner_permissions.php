<?php

return [
    // Calendar / events
    'event.read',
    'event.create',
    'event.update',
    'event.delete',
    'event.see_all',

    // Service orders
    'serviceorder.read',
    'serviceorder.create',
    'serviceorder.update',
    'serviceorder.delete',
    'serviceorder.close',
    'serviceorder.reopen',

    // Service jobs (periodic checks)
    'servicejob.read',
    'servicejob.create',
    'servicejob.update',
    'servicejob.delete',

    // Tickets (storingen)
    'ticket.read',
    'ticket.see_all',
    'ticket.add_to_serviceorder',
    'ticket.detach_from_serviceorder',
    'ticket.change_status',
    'ticket.alter_priority',

    // Customers
    'customer.read',

    // Assets and products (planning context)
    'asset.read',
    'product.read',
    'productrelation.read',
    'productable.read',

    // Activity list
    'activitylist.read',

    // Dashboard
    'dashboard.see_stats',
    'dashboard.see_events',
    'dashboard.see_upcoming_servicejobs',
    'dashboard.see_pending_tickets',
    'dashboard.see_map',
    'dashboard.see_open_serviceorders.all',

    // Documents and images (read-only)
    'document.see',
    'image.see',
];
