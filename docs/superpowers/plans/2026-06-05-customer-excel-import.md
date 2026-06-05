# Customer Excel Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add "Import from Excel" (preview → queue job) and "Download example Excel" buttons to the Customers index page.

**Architecture:** Upload triggers a `preview` endpoint that parses the sheet, stores rows in session, and returns an Inertia preview table. User confirms, which dispatches `ProcessCustomerImportJob` via the queue. Existing customers are matched and updated by name; new ones are created with a UUID snelstart_id. A separate GET endpoint streams an example `.xlsx` download.

**Tech Stack:** `phpoffice/phpspreadsheet`, Laravel queued jobs, Inertia/Vue 3, `useForm` for file upload.

---

## File Map

| Action | Path |
|---|---|
| Create | `app/Http/Controllers/CustomerImportController.php` |
| Create | `app/Http/Requests/CustomerImportPreviewRequest.php` |
| Create | `app/Http/Requests/CustomerImportConfirmRequest.php` |
| Create | `app/Jobs/ProcessCustomerImportJob.php` |
| Modify | `routes/web.php` (add 3 routes after line 57) |
| Modify | `resources/js/Pages/Customers/IndexPage.vue` |

---

### Task 1: Install PhpSpreadsheet

**Files:** none (dependency install)

- [ ] **Step 1: Install the package**

```bash
cd /home/guido/nvme0n1p1/code/lavoro
composer require phpoffice/phpspreadsheet
```

Expected: resolves and installs without conflict.

- [ ] **Step 2: Verify the class is autoloadable**

```bash
php -r "new PhpOffice\PhpSpreadsheet\Spreadsheet(); echo 'OK';"
```

Expected output: `OK`

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add phpoffice/phpspreadsheet"
```

---

### Task 2: Create Form Requests

**Files:**
- Create: `app/Http/Requests/CustomerImportPreviewRequest.php`
- Create: `app/Http/Requests/CustomerImportConfirmRequest.php`

- [ ] **Step 1: Create `CustomerImportPreviewRequest.php`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomerImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('customer.create'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecteer een Excel-bestand.',
            'file.mimes'    => 'Het bestand moet een Excel-bestand zijn (.xlsx of .xls).',
            'file.max'      => 'Het bestand mag niet groter zijn dan 10 MB.',
        ];
    }
}
```

- [ ] **Step 2: Create `CustomerImportConfirmRequest.php`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomerImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('customer.create'));
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/CustomerImportPreviewRequest.php \
        app/Http/Requests/CustomerImportConfirmRequest.php
git commit -m "feat(customers): add import form requests"
```

---

### Task 3: Create ProcessCustomerImportJob

**Files:**
- Create: `app/Jobs/ProcessCustomerImportJob.php`

- [ ] **Step 1: Create the job**

```php
<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessCustomerImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $rows)
    {
    }

    public function handle(): void
    {
        foreach ($this->rows as $row) {
            if ($row['fatal'] || $row['action'] === 'skip') {
                continue;
            }

            $data = array_diff_key($row, array_flip(['action', 'fatal', 'warnings']));

            if (!empty($data['phone'])) {
                $data['phone'] = preg_replace('/\D+/', '', (string) $data['phone']);
            }
            if (!empty($data['postal_code'])) {
                $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
            }

            if ($row['action'] === 'update') {
                Customer::where('name', $data['name'])->first()?->update($data);
            } else {
                $data['snelstart_id'] = (string) Str::uuid();
                Customer::create($data);
            }
        }
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php artisan about 2>&1 | head -5
```

Expected: no parse errors.

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/ProcessCustomerImportJob.php
git commit -m "feat(customers): add ProcessCustomerImportJob"
```

---

### Task 4: Create CustomerImportController

**Files:**
- Create: `app/Http/Controllers/CustomerImportController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerImportPreviewRequest;
use App\Http\Requests\CustomerImportConfirmRequest;
use App\Jobs\ProcessCustomerImportJob;
use App\Models\Customer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CustomerImportController extends Controller
{
    private const COLUMNS = [
        'Naam *'           => 'name',
        'E-mail'           => 'email',
        'Factuur e-mail'   => 'invoice_email',
        'Offertes e-mail'  => 'quotes_email',
        'Telefoon'         => 'phone',
        'Mobiel'           => 'mobile',
        'Website'          => 'website',
        'Contactpersoon'   => 'contactname',
        'Adres'            => 'address',
        'Postcode'         => 'postal_code',
        'Plaats'           => 'city',
        'Land'             => 'country',
        'Postadres'        => 'postal_address',
        'Post postcode'    => 'postal_postal_code',
        'Post plaats'      => 'postal_city',
        'Post land'        => 'postal_country',
        'IBAN'             => 'iban',
        'BTW-nummer'       => 'vat_number',
        'KVK-nummer'       => 'chamber_of_commerce_number',
        'Locatiecode'      => 'location_code',
    ];

    private const EXAMPLE_ROW = [
        'Voorbeeld BV',
        'info@voorbeeld.nl',
        'factuur@voorbeeld.nl',
        'offertes@voorbeeld.nl',
        '0201234567',
        '0612345678',
        'www.voorbeeld.nl',
        'Jan de Vries',
        'Voorbeeldstraat 1',
        '1234AB',
        'Amsterdam',
        'Nederland',
        'Postbus 100',
        '5678CD',
        'Rotterdam',
        'Nederland',
        'NL91ABNA0417164300',
        'NL123456789B01',
        '12345678',
        'LOC001',
    ];

    public function preview(CustomerImportPreviewRequest $request)
    {
        $file = $request->file('file');

        try {
            $spreadsheet = IOFactory::load($file->getPathname());
        } catch (\Exception) {
            return redirect()->route('customers.index')
                ->with('error', 'Het bestand kon niet worden gelezen. Controleer of het een geldig Excel-bestand is.');
        }

        $raw_rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if (empty($raw_rows)) {
            return redirect()->route('customers.index')
                ->with('error', 'Het bestand is leeg.');
        }

        $header = array_shift($raw_rows);
        $col_map = [];
        foreach ($header as $idx => $cell) {
            $cell = trim((string) ($cell ?? ''));
            if (isset(self::COLUMNS[$cell])) {
                $col_map[$idx] = self::COLUMNS[$cell];
            }
        }

        if (!in_array('name', $col_map, true)) {
            return redirect()->route('customers.index')
                ->with('error', 'De kolom "Naam *" ontbreekt in het bestand.');
        }

        $existing_names = Customer::pluck('name')
            ->mapWithKeys(fn ($n) => [mb_strtolower($n) => true])
            ->toArray();

        $preview_rows = [];
        foreach ($raw_rows as $row) {
            $data = [];
            foreach ($col_map as $idx => $field) {
                $data[$field] = trim((string) ($row[$idx] ?? '')) ?: null;
            }

            if (empty(array_filter(array_values($data)))) {
                continue;
            }

            $fatal = empty($data['name']);
            $warnings = [];

            if (!$fatal) {
                if (!empty($data['phone'])) {
                    $digits = preg_replace('/\D+/', '', $data['phone']);
                    if (strlen($digits) !== 10) {
                        $warnings[] = 'Telefoonnummer moet 10 cijfers zijn';
                    }
                }
                if (!empty($data['postal_code']) && !preg_match('/^\d{4}\s?[A-Za-z]{2}$/', $data['postal_code'])) {
                    $warnings[] = 'Ongeldige postcode';
                }
            }

            $action = $fatal
                ? 'skip'
                : (isset($existing_names[mb_strtolower($data['name'])]) ? 'update' : 'create');

            $preview_rows[] = array_merge($data, [
                'action'   => $action,
                'fatal'    => $fatal,
                'warnings' => $warnings,
            ]);
        }

        session(['customer_import_preview' => $preview_rows]);

        $search = $request->input('search');
        $customers = Customer::with(['upcomingAssets', 'openTickets', 'pendingTickets', 'closedTickets'])
            ->orderBy('name')
            ->paginate(25)
            ->appends(['search' => $search]);

        return inertia('Customers/IndexPage', [
            'customers'     => $customers,
            'importPreview' => $preview_rows,
        ]);
    }

    public function confirm(CustomerImportConfirmRequest $request)
    {
        $rows = session('customer_import_preview');

        if (empty($rows)) {
            return redirect()->route('customers.index')
                ->with('error', 'Geen importdata gevonden. Sessie verlopen — upload het bestand opnieuw.');
        }

        session()->forget('customer_import_preview');

        $count = count(array_filter($rows, fn ($r) => !$r['fatal']));
        ProcessCustomerImportJob::dispatch($rows);

        return redirect()->route('customers.index')
            ->with('success', "Import gestart. {$count} klanten worden verwerkt.");
    }

    public function example(CustomerImportConfirmRequest $request)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([array_keys(self::COLUMNS)], null, 'A1');
        $sheet->fromArray([self::EXAMPLE_ROW], null, 'A2');

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'klanten-voorbeeld.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php artisan route:list 2>&1 | head -5
```

Expected: no parse errors (routes not added yet so the list won't show them, but no crash either).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/CustomerImportController.php
git commit -m "feat(customers): add CustomerImportController"
```

---

### Task 5: Register Routes

**Files:**
- Modify: `routes/web.php` (after the `customers.updateCoords` line, ~line 57)

- [ ] **Step 1: Add the three import routes**

Open `routes/web.php`. After this block:
```php
        Route::patch('customers/{customer}/coords', [CustomerController::class, 'updateCoords'])
            ->name('customers.updateCoords');
```

Add:
```php
        Route::post('customers/import/preview', [\App\Http\Controllers\CustomerImportController::class, 'preview'])
            ->name('customers.import.preview');
        Route::post('customers/import/confirm', [\App\Http\Controllers\CustomerImportController::class, 'confirm'])
            ->name('customers.import.confirm');
        Route::get('customers/import/example', [\App\Http\Controllers\CustomerImportController::class, 'example'])
            ->name('customers.import.example');
```

**Important:** these three static routes MUST be registered BEFORE `Route::resource('customers', ...)` or any route with `{customer}` wildcard, otherwise Laravel will try to resolve `import` as a customer ID. Check: the resource route is on line 53 with `->only(['index', 'show', 'update', 'store', 'edit'])` — the three new routes go after the coords patch on line 57, which is already after the resource registration. This is fine because none of the resource routes include a `POST /customers/import/*` path.

- [ ] **Step 2: Verify routes are registered**

```bash
php artisan route:list --name=customers.import
```

Expected output (three rows):
```
POST    customers/import/preview    customers.import.preview
POST    customers/import/confirm    customers.import.confirm
GET     customers/import/example    customers.import.example
```

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat(customers): register import routes"
```

---

### Task 6: Update Customers/IndexPage.vue

**Files:**
- Modify: `resources/js/Pages/Customers/IndexPage.vue`

Replace the entire file content with the following. Read the existing file first to confirm you are not losing any custom logic, then apply the replacement.

- [ ] **Step 1: Replace `IndexPage.vue`**

```vue
<template>
    <IndexHeaderComponent title="Klanten" :addLabel="canCreate && !importPreview ? 'Nieuwe klant' : null"
        search-placeholder="Zoek klant... " search-url="/customers"
        @add="() => canCreate && customerFormRef?.show()">
        <template v-if="!importPreview" #filters>
            <div class="flex flex-wrap gap-2">
                <button @click="importCustomers" :disabled="importingCustomers"
                    class="px-3 py-2 bg-indigo-600 dark:bg-indigo-500 text-white text-xs font-semibold rounded hover:bg-indigo-700 dark:hover:bg-indigo-400 disabled:bg-gray-400 dark:disabled:bg-slate-600/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400 transition">
                    SnelStart klanten importeren
                </button>
                <a href="/customers/import/example"
                    class="px-3 py-2 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-200 text-xs font-semibold rounded hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 transition">
                    Download voorbeeldbestand
                </a>
                <button v-if="canCreate" @click="triggerFileInput" :disabled="previewForm.processing"
                    class="px-3 py-2 bg-emerald-600 dark:bg-emerald-500 text-white text-xs font-semibold rounded hover:bg-emerald-700 dark:hover:bg-emerald-400 disabled:bg-gray-400 dark:disabled:bg-slate-600/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 transition">
                    {{ previewForm.processing ? 'Bezig...' : 'Importeer uit Excel' }}
                </button>
                <input ref="fileInputRef" type="file" accept=".xlsx,.xls" class="hidden" @change="handleFileUpload" />
            </div>
        </template>
    </IndexHeaderComponent>

    <div v-if="!importPreview">
        <div class="mb-4" v-auto-animate v-if="canCreate">
            <CreateRecordForm ref="customerFormRef" external-trigger action="/customers" :fields="customerFields"
                add-button-label="Nieuwe klant" submit-label="Opslaan" />
        </div>
        <PaginationComponent v-if="(customers.links || []).length" :paginator="customers"
            :params="{ search: searchParam }"
            class="border-b border-gray-200 dark:border-slate-700/60" />
        <div class="bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700/60 sm:rounded-lg overflow-hidden">
            <ul role="list" class="divide-y divide-gray-100 dark:divide-slate-800/70">
                <li v-for="customer in customers.data" :key="customer.id">
                    <Link :href="`/customers/${customer.id}`"
                        class="group grid w-full grid-cols-[minmax(0,1fr)_24px] sm:grid-cols-[minmax(0,1fr)_180px_20px] items-start sm:items-center gap-y-2 sm:gap-y-0 gap-x-6 px-4 py-5 hover:bg-gray-50 dark:hover:bg-slate-800/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-500 transition even:bg-gray-50 even:dark:bg-slate-800/40">
                    <div class="flex min-w-0 gap-x-4 col-start-1 row-start-1">
                        <span
                            class="size-12 flex-none rounded-full bg-gray-200 dark:bg-slate-700 ring-1 ring-gray-300 dark:ring-slate-600 flex items-center justify-center text-sm font-medium text-gray-600 dark:text-slate-200 select-none">
                            {{ (customer.name || '?').slice(0, 2).toUpperCase() }}
                        </span>
                        <div class="min-w-0 flex-auto">
                            <p class="text-sm font-semibold leading-6 text-gray-900 dark:text-slate-100 group-hover:underline">
                                {{ customer.name }}
                            </p>
                            <p class="mt-1 truncate text-xs leading-5 text-gray-500 dark:text-slate-400">
                                {{ customer.email || 'Geen e-mailadres' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col items-start justify-center col-start-1 row-start-2 sm:col-start-2 sm:row-start-1 pl-16 sm:pl-0">
                        <p class="text-sm leading-6 text-gray-900 dark:text-slate-200">{{ customer.city || '—' }}</p>
                        <p class="mt-1 text-xs leading-5 text-left text-gray-600 dark:text-slate-400">
                            <span v-if="customer.open_tickets?.length" class="text-red-600 dark:text-red-400 font-medium">{{
                                customer.open_tickets.length }} open stor.</span>
                            <span v-else-if="customer.pending_tickets?.length"
                                class="text-amber-600 dark:text-amber-400 font-medium">{{
                                    customer.pending_tickets.length }} in beh.</span>
                            <span v-else class="text-green-600 dark:text-green-400">Geen open storingen</span>
                        </p>
                    </div>
                    <div class="flex justify-end col-start-2 sm:col-start-3 row-span-2 sm:row-span-1 self-center">
                        <ChevronRightIcon
                            class="size-5 text-gray-400 dark:text-slate-500 group-hover:text-gray-500 dark:group-hover:text-slate-400"
                            aria-hidden="true" />
                        <span class="sr-only">Bekijk {{ customer.name }}</span>
                    </div>
                    </Link>
                </li>
            </ul>
        </div>
        <PaginationComponent v-if="(customers.links || []).length" :paginator="customers"
            :params="{ search: searchParam }"
            class="border-t border-gray-200 dark:border-slate-700/60" />
    </div>

    <div v-else class="mt-4 space-y-4">
        <div class="bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700/60 sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                <thead class="bg-gray-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Naam</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Actie</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Stad</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">Waarschuwingen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                    <tr v-for="(row, idx) in importPreview" :key="idx"
                        :class="row.fatal ? 'bg-red-50 dark:bg-red-900/20' : ''">
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-slate-100">
                            {{ row.name || '— (naam ontbreekt)' }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span v-if="row.fatal"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">
                                Overgeslagen
                            </span>
                            <span v-else-if="row.action === 'update'"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                Bijwerken
                            </span>
                            <span v-else
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">
                                Nieuw
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-300">
                            {{ row.city || '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-amber-600 dark:text-amber-400">
                            {{ row.warnings?.join(', ') || '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center gap-x-4">
            <button @click="handleCancel"
                class="px-4 py-2 text-sm text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 transition">
                Annuleren
            </button>
            <button @click="handleConfirm" :disabled="confirmForm.processing"
                class="px-4 py-2 text-sm text-white bg-indigo-600 dark:bg-indigo-500 rounded hover:bg-indigo-700 dark:hover:bg-indigo-400 disabled:bg-gray-400 dark:disabled:bg-slate-600/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 transition">
                {{ confirmForm.processing ? 'Bezig...' : 'Import bevestigen' }}
            </button>
            <span class="text-xs text-gray-500 dark:text-slate-400">
                {{ importPreview.filter(r => !r.fatal).length }} klanten worden verwerkt
                <span v-if="importPreview.filter(r => r.fatal).length">
                    · {{ importPreview.filter(r => r.fatal).length }} overgeslagen
                </span>
            </span>
        </div>
    </div>
</template>

<script setup>
import { ChevronRightIcon } from '@heroicons/vue/24/outline'
import { Link, router, useForm } from '@inertiajs/vue3';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import { ref, computed } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

const props = defineProps({
    customers: {
        type: Object,
        required: true,
    },
    importPreview: {
        type: Array,
        default: null,
    },
})

const customerFormRef = ref(null)
const fileInputRef = ref(null)

const importingCustomers = ref(false)
const importForm = useForm({})
const importCustomers = () => {
    importingCustomers.value = true;
    importForm.post('/imports/snelstart/customers', {
        preserveScroll: true,
        onFinish: () => importingCustomers.value = false,
    });
}

const previewForm = useForm({ file: null })
const triggerFileInput = () => fileInputRef.value?.click()
const handleFileUpload = (e) => {
    const file = e.target.files[0]
    if (!file) return
    previewForm.file = file
    previewForm.post('/customers/import/preview', { forceFormData: true })
}

const confirmForm = useForm({})
const handleConfirm = () => {
    confirmForm.post('/customers/import/confirm')
}

const handleCancel = () => {
    router.get('/customers')
}

const customerFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'email', label: 'E-mail', type: 'text' },
    { key: 'phone', label: 'Telefoon', type: 'text' },
    { key: 'address', label: 'Adres', type: 'text' },
    { key: 'postal_code', label: 'Postcode', type: 'text' },
    { key: 'city', label: 'Plaats', type: 'text' },
    { key: 'country', label: 'Land', type: 'text' },
    { key: 'location_code', label: 'Locatiecode', type: 'text' },
]

const searchParam = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('search') || '' : ''
const canCreate = computed(() => hasPermission('customer.create'))
</script>
```

- [ ] **Step 2: Run Vite build to catch any frontend errors**

```bash
npm run build 2>&1 | tail -20
```

Expected: build succeeds with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Customers/IndexPage.vue
git commit -m "feat(customers): add Excel import UI and preview table"
```

---

## Self-Review

**Spec coverage check:**
- ✅ Upload button → `triggerFileInput` + hidden file input → `POST /customers/import/preview`
- ✅ Parse sheet, check existing by name, flag action create/update/skip
- ✅ Session storage of preview rows in `preview()`
- ✅ Preview table with Naam/Actie/Stad/Waarschuwingen columns
- ✅ Fatal rows shown in red, badge "Overgeslagen"
- ✅ Confirm button → `POST /customers/import/confirm` → dispatches job → flash
- ✅ Cancel button → `router.get('/customers')`
- ✅ Session read + clear + abort in `confirm()`
- ✅ Queue job creates (with UUID snelstart_id) or updates by name
- ✅ Phone and postal_code sanitized in job (matching CustomerStoreRequest)
- ✅ Example download → `GET /customers/import/example` → streams xlsx
- ✅ All 20 columns in COLUMNS constant, example row for each
- ✅ Auth: `customer.create` on all three endpoints
- ✅ `mimes:xlsx,xls` + max 10 MB on file upload
- ✅ Error flash if file unreadable / missing Naam* column / session expired
- ✅ Empty rows filtered out during parse

**Placeholder scan:** None found.

**Type consistency:** `importPreview` prop is `Array|null` throughout. `row.action` values (`'create'`, `'update'`, `'skip'`) match between controller and template. `row.fatal` boolean used consistently.
