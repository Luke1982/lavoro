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
