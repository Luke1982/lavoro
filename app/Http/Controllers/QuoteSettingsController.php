<?php

namespace App\Http\Controllers;

use App\Domain\Quotes\QuoteFiles;
use App\Domain\Quotes\QuoteLayout;
use App\Domain\Quotes\QuoteSettings;
use App\Http\Requests\QuoteLostReasonSaveRequest;
use App\Http\Requests\QuoteLostReasonsOrderRequest;
use App\Http\Requests\QuoteReadRequest;
use App\Http\Requests\QuoteSettingsDestroyRequest;
use App\Http\Requests\QuoteSettingsReadRequest;
use App\Http\Requests\QuoteSettingsUpdateRequest;
use App\Http\Requests\QuoteTemplateUpdateRequest;
use App\Http\Requests\QuoteTermsStoreRequest;
use App\Http\Requests\QuoteUnitDestroyRequest;
use App\Http\Requests\QuoteUnitsOrderRequest;
use App\Http\Requests\QuoteUnitStoreRequest;
use App\Http\Requests\QuoteUnitUpdateRequest;
use App\Models\QuoteLostReason;
use App\Models\QuoteTemplate;
use App\Models\QuoteUnit;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * The settings that hold for every quote, chapter by chapter: what a quote
 * looks like and says, the follow-up once it is out, the general terms, the
 * units, the reasons a quote is lost for, and the templates.
 */
class QuoteSettingsController extends Controller
{
    public function index(QuoteSettingsReadRequest $request)
    {
        return inertia('Quotes/SettingsPage', [
            'settings' => QuoteSettings::toArray(),
            'layoutChoices' => Arr::except(QuoteLayout::CHOICES, QuoteLayout::PER_CUSTOMER_KIND),
            'units' => QuoteUnit::inOrder()->map->only(['id', 'name']),
            'reasons' => QuoteLostReason::withCount('quotes')->orderBy('position')->get(),
            'templates' => QuoteTemplate::with('createdBy:id,name')->orderBy('name')->get()
                ->map(fn (QuoteTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'lines' => $template->lineCount(),
                    'created_by' => $template->createdBy?->name,
                    'created_at' => $template->created_at,
                ]),
        ]);
    }

    public function update(QuoteSettingsUpdateRequest $request)
    {
        QuoteSettings::save($request->validated());

        return redirect()->back();
    }

    public function storeTerms(QuoteTermsStoreRequest $request)
    {
        QuoteSettings::storeTerms($request->file('terms'));

        return redirect()->back()
            ->with('success', 'Algemene voorwaarden opgeslagen. Ze gaan mee met alles wat je vanaf nu verstuurt.');
    }

    public function destroyTerms(QuoteSettingsDestroyRequest $request)
    {
        QuoteSettings::removeTerms();

        return redirect()->back()->with('success', 'Offertes gaan vanaf nu zonder algemene voorwaarden de deur uit.');
    }

    /** The general terms as they stand, for whoever may read quotes: the preview of a quote links to them. */
    public function terms(QuoteReadRequest $request): Response
    {
        return QuoteFiles::open(QuoteSettings::terms());
    }

    public function storeUnit(QuoteUnitStoreRequest $request)
    {
        QuoteUnit::create($request->validated());

        return redirect()->back();
    }

    public function updateUnit(QuoteUnitUpdateRequest $request, QuoteUnit $unit)
    {
        $unit->update($request->validated());

        return redirect()->back();
    }

    public function destroyUnit(QuoteUnitDestroyRequest $request, QuoteUnit $unit)
    {
        $unit->delete();

        return redirect()->back();
    }

    public function orderUnits(QuoteUnitsOrderRequest $request)
    {
        QuoteUnit::putInOrder(array_map('intval', $request->validated('unit_ids')));

        return redirect()->back();
    }

    public function storeReason(QuoteLostReasonSaveRequest $request)
    {
        QuoteLostReason::create($request->validated());

        return redirect()->back();
    }

    public function updateReason(QuoteLostReasonSaveRequest $request, QuoteLostReason $reason)
    {
        $reason->update($request->validated());

        return redirect()->back();
    }

    /** The quotes lost for it keep their status; they just no longer say why. */
    public function destroyReason(QuoteSettingsDestroyRequest $request, QuoteLostReason $reason)
    {
        $reason->delete();

        return redirect()->back();
    }

    public function orderReasons(QuoteLostReasonsOrderRequest $request)
    {
        QuoteLostReason::putInOrder(array_map('intval', $request->validated('reason_ids')));

        return redirect()->back();
    }

    public function updateTemplate(QuoteTemplateUpdateRequest $request, QuoteTemplate $template)
    {
        $template->update($request->validated());

        return redirect()->back();
    }

    public function destroyTemplate(QuoteSettingsDestroyRequest $request, QuoteTemplate $template)
    {
        $template->delete();

        return redirect()->back();
    }
}
