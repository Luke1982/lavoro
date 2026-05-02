<?php

return [
    // Service orders — invoicing flow (no close/reopen)
    'serviceorder.read',
    'serviceorder.see_financials',
    'serviceorder.export_pdf',
    'serviceorder.email_pdf',
    'serviceorder.email_pdf_with_jobs',

    // Service jobs — read + PDF
    'servicejob.read',
    'servicejob.export_pdf',
    'servicejob.mail_pdf',

    // Snelstart accounting integration
    'snelstart.send_serviceorder',
    'snelstart.get_customers',
    'snelstart.get_articles',

    // Customers — read + billing edits
    'customer.read',
    'customer.update',

    // Read-only context for invoicing
    'material.read',
    'product.read',
    'asset.read',
    'ticket.read',
    'ticket.see_all',
    'event.read',

    // Documents — see + upload invoices + update (no delete)
    'document.see',
    'document.upload',
    'document.update',

    'image.see',

    // Activity list
    'activitylist.read',

    // Pricing
    'product.view_prices',

    // Dashboard — financial widgets
    'dashboard.see_stats',
    'dashboard.see_open_serviceorders.not_sent',
    'dashboard.see_open_serviceorders.sent_administration',
    'dashboard.see_open_serviceorders.sent_customer',
    'dashboard.see_open_serviceorders.all',
];
