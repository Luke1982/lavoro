<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\ExportCollectionRequest;
use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Services\SepaDirectDebit;
use Carbon\CarbonImmutable;

/**
 * Collection: the SEPA file for the bank.
 */
class CollectionController extends Controller
{
    public function collections()
    {
        return inertia('Landlord/CollectionsPage', [
            'invoices' => $this->collectable()->get(),
            'issuer' => (object) IssuerSetting::all_values(),
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
         * Only stamp them once the file is there. A customer already marked as
         * collected while the bank got nothing is never included again.
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
     * An invoice may travel along when the customer has given a mandate and it
     * was not in a file before.
     */
    private function collectable()
    {
        return Invoice::on('central')
            ->with('tenant')
            ->whereNull('collected_at')
            /** A credit note cannot be collected; paying money back is done by hand. */
            ->where('gross_cents', '>', 0)
            ->whereHas('tenant', fn ($query) => $query
                ->where('payment_method', 'direct_debit')
                ->whereNotNull('iban')
                ->whereNotNull('mandate_reference')
                ->whereNotNull('mandate_signed_on'))
            ->orderBy('number');
    }
}
