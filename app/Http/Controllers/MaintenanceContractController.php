<?php

namespace App\Http\Controllers;

use App\Enums\ContractInterval;
use App\Http\Requests\MaintenanceContractAttachAssetRequest;
use App\Http\Requests\MaintenanceContractDestroyRequest;
use App\Http\Requests\MaintenanceContractDetachAssetRequest;
use App\Http\Requests\MaintenanceContractGenerateServiceOrdersRequest;
use App\Http\Requests\MaintenanceContractReadRequest;
use App\Http\Requests\MaintenanceContractStoreRequest;
use App\Http\Requests\MaintenanceContractUpdateAssetableRequest;
use App\Http\Requests\MaintenanceContractUpdateRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Services\MaintenanceContractServiceOrderGenerator;
use Carbon\Carbon;

class MaintenanceContractController extends Controller
{
    public function index(MaintenanceContractReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = MaintenanceContract::query()->with('customer');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        }

        if ($request->filled('onlyStatus')) {
            $today = now()->toDateString();
            match ($request->input('onlyStatus')) {
                'geannuleerd' => $query->whereNotNull('cancelled_at'),
                'toekomstig' => $query->whereNull('cancelled_at')->where('start_date', '>', $today),
                'verlopen' => $query->whereNull('cancelled_at')
                    ->where('start_date', '<=', $today)
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', $today),
                'actief' => $query->whereNull('cancelled_at')
                    ->where('start_date', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today)),
                default => null,
            };
        }

        $maintenancecontracts = $query->orderByDesc('start_date')->paginate(20)->withQueryString();

        return inertia('MaintenanceContracts/IndexPage', [
            'maintenanceContracts' => $maintenancecontracts,
            'allCustomers' => Customer::select('id', 'name')->orderBy('name')->get(),
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
            'search' => $search,
            'onlyStatus' => $request->input('onlyStatus', ''),
        ]);
    }

    public function show(MaintenanceContractReadRequest $request, MaintenanceContract $maintenancecontract)
    {
        $maintenancecontract->load([
            'customer.assets.product.brand',
            'customer.assets.product.productType',
            'customer.assets.product.images',
            'customer.assets.location',
            'assets.product.brand',
            'assets.product.productType',
            'assets.product.images',
            'assets.location',
            'activities' => function ($q) {
                $q->with('user:id,name')->orderByDesc('activityables.created_at');
            },
            'remarks.user',
            'generatedServiceOrders.serviceJobs.asset.product.brand',
        ]);

        return inertia('MaintenanceContracts/ShowPage', [
            'maintenanceContract' => $maintenancecontract,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'contractIntervalOptions' => ContractInterval::comboBoxArray(),
        ]);
    }

    public function store(MaintenanceContractStoreRequest $request)
    {
        $maintenancecontract = MaintenanceContract::create($request->validated());
        $maintenancecontract->logActivity('Contract aangemaakt');

        return redirect()->back()->with('success', 'Onderhoudscontract aangemaakt.');
    }

    public function update(MaintenanceContractUpdateRequest $request, MaintenanceContract $maintenancecontract)
    {
        $validated = $request->validated();
        $was_contract_wide = !$maintenancecontract->manage_frequency_per_asset;
        $original = $maintenancecontract->getAttributes();

        if (array_key_exists('cancelled', $validated)) {
            $is_already_cancelled = $maintenancecontract->cancelled_at !== null;
            if ($validated['cancelled'] && !$is_already_cancelled) {
                $maintenancecontract->cancelled_at = now();
                $maintenancecontract->logActivity('Contract geannuleerd');
            } elseif (!$validated['cancelled'] && $is_already_cancelled) {
                $maintenancecontract->cancelled_at = null;
                $maintenancecontract->logActivity('Contract heractiveerd');
            }
            $maintenancecontract->save();
        }

        $maintenancecontract->update($validated);

        $switched_to_individual = array_key_exists('manage_frequency_per_asset', $validated)
            && $validated['manage_frequency_per_asset']
            && $was_contract_wide;

        if ($switched_to_individual) {
            $maintenancecontract->assets()
                ->newPivotQuery()
                ->whereNull('frequency')
                ->update([
                    'frequency' => $validated['frequency'] ?? $maintenancecontract->getRawOriginal('frequency'),
                    'frequency_days' => $validated['frequency_days'] ?? $maintenancecontract->frequency_days,
                ]);
            $maintenancecontract->logActivity('Frequentiebeheer gewijzigd naar per machine');
        } elseif (array_key_exists('manage_frequency_per_asset', $validated) && !$validated['manage_frequency_per_asset']) {
            $maintenancecontract->logActivity('Frequentiebeheer gewijzigd naar contractbreed');
        }

        if (array_key_exists('auto_generate', $validated)) {
            $was_auto = (bool) ($original['auto_generate'] ?? false);
            $will_be_auto = (bool) $validated['auto_generate'];

            if ($will_be_auto && !$was_auto) {
                $interval = $validated['auto_generate_interval'] ?? null;
                $label = $interval
                    ? $this->frequencyLabel($interval, $validated['auto_generate_interval_days'] ?? null)
                    : 'contractfrequentie';
                $maintenancecontract->logActivity(
                    "Automatisch genereren van werkbonnen ingeschakeld (frequentie: {$label})"
                );
            } elseif (!$will_be_auto && $was_auto) {
                $maintenancecontract->logActivity('Automatisch genereren van werkbonnen uitgeschakeld');
            }
        }

        $this->logFieldChanges($maintenancecontract, $validated, $original);

        return redirect()->back()->with('success', 'Onderhoudscontract bijgewerkt.');
    }

    private function logFieldChanges(MaintenanceContract $maintenancecontract, array $validated, array $original): void
    {
        if (array_key_exists('customer_id', $validated) && (string) $validated['customer_id'] !== (string) ($original['customer_id'] ?? '')) {
            $old_customer = Customer::find($original['customer_id'] ?? null)?->name ?? 'onbekend';
            $new_customer = Customer::find($validated['customer_id'])?->name ?? 'onbekend';
            $maintenancecontract->logActivity("Klant gewijzigd van '{$old_customer}' naar '{$new_customer}'");
        }

        $labels = [
            'title' => 'Titel',
            'start_date' => 'Startdatum',
            'end_date' => 'Einddatum',
            'price' => 'Prijs',
            'price_interval' => 'Prijsinterval',
            'price_interval_days' => 'Prijsinterval (dagen)',
            'frequency' => 'Servicefrequentie',
            'frequency_days' => 'Servicefrequentie (dagen)',
        ];

        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $old_value = $original[$field] ?? null;
            $new_value = $validated[$field];

            if ((string) $old_value === (string) $new_value) {
                continue;
            }

            $maintenancecontract->logActivity(
                $this->fieldChangeMessage($label, $this->formatFieldValue($field, $old_value), $this->formatFieldValue($field, $new_value))
            );
        }
    }

    private function fieldChangeMessage(string $label, string $old_display, string $new_display): string
    {
        if ($old_display === '—' && $new_display !== '—') {
            return "{$label} ingesteld op '{$new_display}'";
        }

        if ($new_display === '—' && $old_display !== '—') {
            return "{$label} verwijderd (was '{$old_display}')";
        }

        return "{$label} gewijzigd van '{$old_display}' naar '{$new_display}'";
    }

    private function formatFieldValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            'start_date', 'end_date' => Carbon::parse($value)->format('d-m-Y'),
            'price' => "\u{20AC}" . number_format((float) $value, 2, ',', '.'),
            default => (string) $value,
        };
    }

    public function destroy(MaintenanceContractDestroyRequest $request, MaintenanceContract $maintenancecontract)
    {
        $maintenancecontract->delete();

        return redirect()->route('maintenancecontracts.index')->with('success', 'Onderhoudscontract verwijderd.');
    }

    public function generateServiceOrders(
        MaintenanceContractGenerateServiceOrdersRequest $request,
        MaintenanceContract $maintenancecontract
    ) {
        $service_orders = (new MaintenanceContractServiceOrderGenerator)->generateNowForContract($maintenancecontract);
        $count = count($service_orders);

        if ($count === 0) {
            $message = 'Voeg eerst machines toe aan het contract voordat je een werkbon aanmaakt.';
        } elseif ($count === 1) {
            $message = "Werkbon #{$service_orders[0]->id} aangemaakt.";
        } else {
            $message = "{$count} werkbonnen aangemaakt (één per locatie).";
        }

        return redirect()->back()->with('success', $message);
    }

    public function attachAsset(
        MaintenanceContractAttachAssetRequest $request,
        MaintenanceContract $maintenancecontract,
        Asset $asset
    ) {
        $validated = $request->validated();
        $frequency = $validated['frequency'] ?? null;
        $frequency_days = $validated['frequency_days'] ?? null;
        $maintenancecontract->assets()->attach($asset->id, [
            'frequency' => $frequency,
            'frequency_days' => $frequency_days,
        ]);

        $message = 'Machine gekoppeld: ' . $this->assetLabel($asset);
        if ($frequency) {
            $message .= ' (frequentie: ' . $this->frequencyLabel($frequency, $frequency_days) . ')';
        }
        $maintenancecontract->logActivity($message);

        return redirect()->back()->with('success', 'Machine gekoppeld aan het contract.');
    }

    public function updateAssetable(
        MaintenanceContractUpdateAssetableRequest $request,
        MaintenanceContract $maintenancecontract,
        string $assetable_id
    ) {
        $pivot_query = $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id);
        $record = $pivot_query->first();
        $asset = $record ? Asset::find($record->asset_id) : null;

        $pivot_query->update($request->validated());

        if ($asset) {
            $updated = $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id)->first();
            $maintenancecontract->logActivity(sprintf(
                'Machinefrequentie bijgewerkt: %s naar %s',
                $this->assetLabel($asset),
                $this->frequencyLabel($updated->frequency, $updated->frequency_days)
            ));
        }

        return redirect()->back()->with('success', 'Frequentie bijgewerkt.');
    }

    public function detachAsset(
        MaintenanceContractDetachAssetRequest $request,
        MaintenanceContract $maintenancecontract,
        string $assetable_id
    ) {
        $pivot_query = $maintenancecontract->assets()->newPivotQuery()->where('assetables.id', $assetable_id);
        $record = $pivot_query->first();
        $asset = $record ? Asset::find($record->asset_id) : null;

        $pivot_query->delete();

        $maintenancecontract->logActivity(
            'Machine losgekoppeld' . ($asset ? ': ' . $this->assetLabel($asset) : '')
        );

        return redirect()->back()->with('success', 'Machine losgekoppeld van het contract.');
    }

    private function assetLabel(Asset $asset): string
    {
        $asset->loadMissing('product.brand');
        $name = trim(implode(' ', array_filter([
            $asset->product?->brand?->name,
            $asset->product?->model,
        ])));

        if ($asset->serial_number) {
            return $name !== '' ? "{$name} ({$asset->serial_number})" : $asset->serial_number;
        }

        return $name !== '' ? $name : ('#' . $asset->id);
    }

    private function frequencyLabel(?string $frequency, ?int $frequency_days): string
    {
        if (!$frequency) {
            return 'onbekend';
        }

        if ($frequency === ContractInterval::aangepast->value && $frequency_days) {
            return $frequency . ' (' . $frequency_days . ' dagen)';
        }

        return $frequency;
    }
}
