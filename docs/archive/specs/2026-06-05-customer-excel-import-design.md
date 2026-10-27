# Customer Excel Import — Design Spec

Date: 2026-06-05

## Overview

Add two buttons to the Customers index page:

1. **"Importeer uit Excel"** — opens a file picker, uploads an `.xlsx`/`.xls` file, shows a preview, then dispatches a queued job to create/update customers.
2. **"Download voorbeeldbestand"** — downloads an empty example Excel with the correct column headers.

## User Flow

1. User clicks **"Importeer uit Excel"** on `/customers` (gated on `customer.create` permission).
2. A hidden `<input type="file">` triggers; user picks an `.xlsx` or `.xls` file.
3. The file is POSTed to `POST /customers/import/preview`.
4. The backend parses the sheet and returns a preview payload (no DB writes at this stage).
5. The index page swaps its customer list for a **preview table** (via `v-if`), showing each row with:
   - Name, city, action badge (`Nieuw` in green / `Bijwerken` in amber), and any validation warnings (e.g. invalid postcode format).
   - Rows with a fatal error (e.g. missing name) are shown in red and will be skipped.
6. User reviews and clicks **"Import bevestigen"**.
7. `POST /customers/import/confirm` stores the validated rows in the session and dispatches `ProcessCustomerImportJob`.
8. User is redirected to `/customers` with a flash: *"Import gestart. X klanten worden verwerkt."*
9. The queue job runs asynchronously: creates new customers or updates existing ones matched by `name`. Session data is cleared after the job completes.

**Cancel:** A "Annuleren" button on the preview screen discards the preview and returns to the normal customer list.

## Backend

### New package

`phpoffice/phpspreadsheet` — installed via `composer require`.

### Routes (in `routes/web.php`, inside the `auth` middleware group)

```
POST /customers/import/preview    → CustomerImportController@preview
POST /customers/import/confirm    → CustomerImportController@confirm
GET  /customers/import/example    → CustomerImportController@example
```

### `CustomerImportController`

**`preview(CustomerImportPreviewRequest $request)`**
- Auth: `customer.create` permission.
- Validates file is present and is `.xlsx`/`.xls`.
- Reads the sheet using PhpSpreadsheet; maps rows to an array keyed by the column names below.
- For each row: checks if a Customer with the same `name` exists → sets `action = 'update'` or `action = 'create'`.
- Runs lightweight per-field validation (same rules as `CustomerStoreRequest` where applicable).
- Stores the parsed rows in the session under key `customer_import_preview`.
- Returns an Inertia response to `Customers/IndexPage` with an `importPreview` prop containing the parsed rows (for display).

**`confirm(CustomerImportConfirmRequest $request)`**
- Auth: `customer.create` permission.
- Reads rows from session key `customer_import_preview`; aborts with error flash if missing or empty (expired session).
- Dispatches `ProcessCustomerImportJob` with the session rows.
- Clears the session key.
- Redirects to `/customers` with flash success.

**`example()`**
- Auth: `customer.create` permission.
- Generates an in-memory `.xlsx` with one header row and one example data row.
- Streams it as a download: `klanten-voorbeeld.xlsx`.

### `ProcessCustomerImportJob`

- Implements `ShouldQueue`.
- Receives the array of validated rows.
- For each row:
  - If `action = 'update'`: finds the Customer by `name`, calls `update()` with the row data.
  - If `action = 'create'`: calls `Customer::create()` with the row data, auto-generates `snelstart_id` (UUID) same as the regular store flow.
- Skips rows flagged with fatal errors during preview.

### Form Requests

- `CustomerImportPreviewRequest` — auth via `customer.create`; validates `file` is required, mimes `xlsx,xls`, max 10 MB.
- `CustomerImportConfirmRequest` — auth via `customer.create`; validates session key `customer_import_preview` is a non-empty array.

## Frontend (`Customers/IndexPage.vue`)

### New props

```js
defineProps({
  customers: Object,        // existing
  importPreview: {          // null when not in preview mode
    type: Array,
    default: null,
  },
})
```

### Layout swap

```
v-if="importPreview === null"  → normal customer list
v-else                         → preview table + confirm/cancel buttons
```

### Preview table columns

Name | Actie | Stad | Waarschuwingen

### Buttons added to the `#filters` slot (when not in preview mode)

- **"Download voorbeeld"** — `<a href="/customers/import/example">` (simple link, no JS needed).
- **"Importeer uit Excel"** — triggers hidden `<input type="file" accept=".xlsx,.xls">`, submits via Inertia `useForm`.

## Excel Template Columns

| Internal key | Dutch header label |
|---|---|
| name | Naam * |
| email | E-mail |
| invoice_email | Factuur e-mail |
| quotes_email | Offertes e-mail |
| phone | Telefoon |
| mobile | Mobiel |
| website | Website |
| contactname | Contactpersoon |
| address | Adres |
| postal_code | Postcode |
| city | Plaats |
| country | Land |
| postal_address | Postadres |
| postal_postal_code | Post postcode |
| postal_city | Post plaats |
| postal_country | Post land |
| iban | IBAN |
| vat_number | BTW-nummer |
| chamber_of_commerce_number | KVK-nummer |
| location_code | Locatiecode |

`name` is required; all others are optional. `name` is the match key for updates.

Excluded from import: `snelstart_id`, `billing_customer_id`, `lat`, `lon`.

## Error Handling

- File parse errors (corrupted file, wrong format) → return Inertia error, redirect back with flash.
- Missing required `name` column in header → abort with error flash.
- Individual row missing `name` → row flagged as fatal error in preview; skipped by the job.
- Session mismatch on confirm (e.g. session expired) → redirect back with error flash.

## Permissions

All three endpoints require `customer.create` (same as the existing store endpoint).
