<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\ExportCollectionRequest;
use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Services\SepaDirectDebit;
use Carbon\CarbonImmutable;

/**
 * Incasso: het SEPA-bestand voor de bank.
 */
class CollectionController extends Controller
{
    public function collections()
    {
        return view('landlord.collections', [
            'invoices' => $this->collectable()->get(),
            'issuer' => IssuerSetting::all_values(),
            'collect_on' => now()->addWeekdays(6)->toDateString(),
        ]);
    }

    public function exportCollection(ExportCollectionRequest $request)
    {
        $data = $request->validated();

        $invoices = $this->collectable()
            ->whereIn('id', $data['invoices'])
            ->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', 'Niets te incasseren.');
        }

        $batch = 'LVR-' . now()->format('YmdHis');

        $xml = (new SepaDirectDebit(
            $invoices,
            CarbonImmutable::parse($data['collect_on']),
            $batch,
        ))->toXml();

        /**
         * Pas afstempelen als het bestand er is. Een klant die al op
         * "geïncasseerd" staat terwijl de bank niets gekregen heeft, wordt
         * nooit meer meegenomen.
         */
        Invoice::on('central')
            ->whereIn('id', $invoices->pluck('id'))
            ->update(['collected_at' => now(), 'collection_batch' => $batch]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $batch . '.xml"',
        ]);
    }

    /**
     * Een factuur mag mee als de klant een machtiging heeft afgegeven en hij
     * nog niet eerder in een bestand zat.
     */
    private function collectable()
    {
        return Invoice::on('central')
            ->with('tenant')
            ->whereNull('collected_at')
            ->whereHas('tenant', fn ($query) => $query
                ->where('payment_method', 'direct_debit')
                ->whereNotNull('iban')
                ->whereNotNull('mandate_reference')
                ->whereNotNull('mandate_signed_on'))
            ->orderBy('number');
    }
}
