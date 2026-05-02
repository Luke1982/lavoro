<?php

return [
    // Customers (no delete — historical records)
    'customer.read',
    'customer.create',
    'customer.update',

    // Assets (no delete — historical records)
    'asset.read',
    'asset.create',
    'asset.update',

    // Materials (no delete — historical records)
    'material.read',
    'material.create',
    'material.update',

    // Products (no delete — historical records)
    'product.read',
    'product.create',
    'product.update',

    // Product relations (read + manage)
    'productrelation.read',
    'productrelation.create',
    'productrelation.update',
    'productrelation.delete',

    // Productables (read + manage)
    'productable.read',
    'productable.create',
    'productable.delete',

    // Asset relations
    'assetrelation.create',
    'assetrelation.delete',

    // Master-data CRUD on smaller catalog entities
    'producttype.read',
    'producttype.create',
    'producttype.update',
    'producttype.delete',

    'brand.read',
    'brand.create',
    'brand.update',
    'brand.delete',

    'materialcategory.read',
    'materialcategory.create',
    'materialcategory.update',
    'materialcategory.delete',

    'materialusageunit.read',
    'materialusageunit.create',
    'materialusageunit.update',
    'materialusageunit.delete',

    'servicecheck.read',
    'servicecheck.create',
    'servicecheck.update',
    'servicecheck.delete',

    'servicecheckgroup.read',
    'servicecheckgroup.create',
    'servicecheckgroup.update',
    'servicecheckgroup.delete',

    'eventtype.read',
    'eventtype.create',
    'eventtype.update',
    'eventtype.delete',

    // Custom fields (read-only — system config is admin territory)
    'customfield.read',

    // Tickets — intake, triage, attaching to service orders
    'ticket.read',
    'ticket.create',
    'ticket.update',
    'ticket.see_all',
    'ticket.change_status',
    'ticket.alter_priority',
    'ticket.add_to_serviceorder',
    'ticket.detach_from_serviceorder',

    // Operational visibility (read-only)
    'serviceorder.read',
    'serviceorder.email_pdf',
    'serviceorder.email_pdf_with_jobs',
    'servicejob.read',
    'event.read',

    // Documents and images — full curation
    'document.see',
    'document.upload',
    'document.update',
    'document.delete',

    'image.see',
    'image.upload',
    'image.update',
    'image.edit',
    'image.delete',

    // Activity list
    'activitylist.read',

    // Dashboard
    'dashboard.see_stats',
    'dashboard.see_events',
    'dashboard.see_map',
    'dashboard.see_pending_tickets',
    'dashboard.see_upcoming_servicejobs',
    'dashboard.see_open_serviceorders.not_sent',
    'dashboard.see_open_serviceorders.sent_administration',
    'dashboard.see_open_serviceorders.sent_customer',
    'dashboard.see_open_serviceorders.all',
];
