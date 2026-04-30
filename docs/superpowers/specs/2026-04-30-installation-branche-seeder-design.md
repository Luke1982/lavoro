# InstallationBrancheSeeder — Design

**Date:** 2026-04-30
**Status:** Draft for review

## Purpose

A "branche" (industry) seeder that prepares the database for installation-industry customers. It seeds roles with their permissions and reference data. No users are created — those will live in a customer-specific seeder that follows.

This parallels `AutomotiveEquipmentSeeder` in shape: per-industry baseline data, intended to be run once on a fresh installation before customer-specific data is layered on top.

## Scope

### In scope

1. Seven roles, idempotently created with their permissions synced:
   - `admin` (role only, no permissions; admin bypasses authorization via Gate)
   - `Monteur` (permissions from existing `data/monteur_permissions.php`)
   - `Projectleider` (new permission list, see below)
   - `Projectmanager` (new permission list, see below)
   - `Planner` (new permission list, see below)
   - `Binnendienst` (new permission list, see below)
   - `Administratie` (new permission list, see below)

2. Event types (matching `DevelopmentSeeder`):
   - Periodieke controle (`#388e3c`)
   - Oplossen storing (`#d32f2f`)
   - Controle met storingen (`#fbc02d`)
   - Inventarisatie (`#7b1fa2`)

3. Material usage units, loaded from `database/seeders/data/general/usage_units.php`:
   `Stuks`, `Uur`, `Liter`, `Kilogram`, `Meter`, `Set`.

### Out of scope

- Users (will be created in the customer-specific seeder).
- Products, brands, customers, materials, service checks, tickets, service orders, events, assets.

## Files

### New files

- `database/seeders/InstallationBrancheSeeder.php` — the seeder itself.
- `database/seeders/data/projectleider_permissions.php` — returns an array of permission names.
- `database/seeders/data/projectmanager_permissions.php` — returns an array of permission names.
- `database/seeders/data/planner_permissions.php` — returns an array of permission names.
- `database/seeders/data/binnendienst_permissions.php` — returns an array of permission names.
- `database/seeders/data/administratie_permissions.php` — returns an array of permission names.

### Files referenced (not modified)

- `database/seeders/data/monteur_permissions.php`
- `database/seeders/data/general/usage_units.php`

### Files NOT modified

- `database/seeders/DatabaseSeeder.php` — kept empty per existing convention; the new seeder is run manually like its siblings.

## Permission lists

### Projectleider (`data/projectleider_permissions.php`)

```php
return [
    'projects.lead',
    'project.read',
    'project.update',
    'projectmilestone.read',
    'projectmilestone.create',
    'projectmilestone.update',
    'projectmilestone.delete',
];
```

Rationale: `projects.lead` makes the user selectable as a project leader. `project.read` and `project.update` give them edit rights — ownership filtering ("only own projects") is the responsibility of policies/scopes, not the permission seed. Project leaders can fully manage milestones within their projects.

### Projectmanager (`data/projectmanager_permissions.php`)

```php
return [
    'project.read',
    'project.create',
    'project.update',
    'project.delete',
    'projectmilestone.read',
    'projectmilestone.create',
    'projectmilestone.update',
    'projectmilestone.delete',
];
```

Rationale: full CRUD on projects and milestones, organisation-wide. `projects.lead` is intentionally excluded — only Projectleider users (and admin) should be assignable as project leaders.

### Planner (`data/planner_permissions.php`)

```php
return [
    // Calendar / events
    'event.read', 'event.create', 'event.update', 'event.delete', 'event.see_all',
    // Service orders (incl. close/reopen)
    'serviceorder.read', 'serviceorder.create', 'serviceorder.update', 'serviceorder.delete',
    'serviceorder.close', 'serviceorder.reopen',
    // Service jobs
    'servicejob.read', 'servicejob.create', 'servicejob.update', 'servicejob.delete',
    // Tickets
    'ticket.read', 'ticket.see_all',
    'ticket.add_to_serviceorder', 'ticket.detach_from_serviceorder',
    'ticket.change_status', 'ticket.alter_priority',
    // Customers (read-only)
    'customer.read',
    // Planning context
    'asset.read', 'product.read', 'activitylist.read',
    // Dashboard
    'dashboard.see_stats', 'dashboard.see_events',
    'dashboard.see_upcoming_servicejobs', 'dashboard.see_pending_tickets',
    'dashboard.see_map', 'dashboard.see_open_serviceorders.all',
    // Documents / images (read-only)
    'document.see', 'image.see',
];
```

Rationale: a Planner schedules and dispatches work. They need full lifecycle on events, service orders (including close/reopen) and service jobs; full ticket workflow control (status, priority, attaching to orders); read-only access to customers, assets, products, documents and images for context; and the planning-relevant dashboard widgets. Explicitly excluded: financials (`serviceorder.see_financials`), PDF export/email, customer mutation, asset mutation, user/role management.

### Binnendienst (`data/binnendienst_permissions.php`)

Back-office / inside sales — owns master data, ticket intake, customer support, document curation. Does not plan work and does not see financials.

Permissions:

- **Master data, no delete** (historical records): `customer.{read,create,update}`, `asset.{read,create,update}`, `material.{read,create,update}`, `product.{read,create,update}`.
- **Master data, full CRUD** (smaller catalogs): `producttype.*`, `brand.*`, `materialcategory.*`, `materialusageunit.*`, `servicecheck.*`, `servicecheckgroup.*`, `eventtype.*`.
- **Custom fields, read-only**: `customfield.read` (system config remains admin-only).
- **Tickets**: `ticket.{read,create,update,see_all,change_status,alter_priority,add_to_serviceorder,detach_from_serviceorder}`.
- **Operational visibility (read)**: `serviceorder.read`, `servicejob.read`, `event.read`, plus `serviceorder.email_pdf`/`serviceorder.email_pdf_with_jobs` for re-sending docs.
- **Documents and images, full curation**: `document.{see,upload,update,delete}`, `image.{see,upload,update,edit,delete}`.
- **Activity list and dashboard**: `activitylist.read`, plus dashboard widgets (`see_stats`, `see_events`, `see_map`, `see_pending_tickets`, `see_upcoming_servicejobs`, all four `see_open_serviceorders.*`).

Explicitly excluded: `serviceorder.see_financials`, `serviceorder.{create,update,delete,close,reopen}`, `servicejob.{create,update,delete}`, `snelstart.*`, `event.{create,update,delete,see_all}`, projects/milestones, customer/asset/material/product `.delete`, `customfield.{create,update,delete}`.

### Administratie (`data/administratie_permissions.php`)

Financial administration — invoicing flow, accounting integration, financial visibility on service orders.

Permissions:

- **Service orders — invoice flow**: `serviceorder.read`, `serviceorder.see_financials`, `serviceorder.export_pdf`, `serviceorder.email_pdf`, `serviceorder.email_pdf_with_jobs`. Excluded: `serviceorder.close`, `serviceorder.reopen`.
- **Service jobs — read + PDFs**: `servicejob.read`, `servicejob.export_pdf`, `servicejob.mail_pdf`.
- **Snelstart accounting integration**: `snelstart.send_serviceorder`, `snelstart.get_customers`, `snelstart.get_articles`.
- **Customers — read + billing edits**: `customer.read`, `customer.update`.
- **Read-only context for invoicing**: `material.read`, `product.read`, `asset.read`, `ticket.read`, `ticket.see_all`, `event.read`.
- **Documents — see, upload, update**: `document.see`, `document.upload`, `document.update` (no delete — financial documents are immutable post-upload). Plus `image.see`.
- **Activity list and dashboard**: `activitylist.read`, `dashboard.see_stats`, all four `dashboard.see_open_serviceorders.*` widgets.

Explicitly excluded: master-data CRUD, planning, ticket workflow actions (status/priority/attach), projects/milestones, image edit/upload/delete, document delete, `serviceorder.{create,update,delete,close,reopen}`.

## Idempotency

All inserts use `firstOrCreate`. All role-permission attachments use `syncWithoutDetaching` so re-runs do not strip permissions added through other means. Material usage units and event types use `firstOrCreate` keyed on `name` (and `name`+`color` for event types, matching DevelopmentSeeder).

## Acceptance criteria

- Running `php artisan db:seed --class=InstallationBrancheSeeder` on a fresh installation:
  - Creates seven roles with the listed names.
  - Each role's `permissions` relation contains exactly the permissions listed above (admin is empty).
  - Creates the four event types with the specified colors.
  - Creates the six usage units.
  - Creates no users.
- Running it a second time produces no errors and no duplicates.
- Running it after `DevelopmentSeeder` does not strip Monteur permissions or duplicate roles/event types/usage units.

## Out-of-scope follow-ups (noted, not part of this work)

- Customer-specific seeder that creates users and assigns the four roles.
- "Add documents upload to projects" feature (user reminder).
