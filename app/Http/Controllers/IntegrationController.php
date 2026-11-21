<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\DisconnectIntegrationAction;
use App\Domain\Integrations\Area;
use App\Domain\Integrations\Connectors;
use App\Domain\Integrations\Data\Field;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Domain\Signals\Signals;
use App\Http\Requests\Integrations\IntegrationIndexRequest;
use App\Http\Requests\Integrations\IntegrationManageRequest;
use App\Http\Requests\Integrations\IntegrationPullRequest;
use App\Http\Requests\Integrations\IntegrationStoreRequest;
use App\Http\Requests\Integrations\IntegrationUpdateRequest;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRun;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Instellingen → Koppelingen: which outside systems this customer uses, and per
 * system what goes which way.
 */
class IntegrationController extends Controller
{
    public function index(IntegrationIndexRequest $request): Response
    {
        $connections = IntegrationConnection::with(['runs' => fn ($runs) => $runs->latest('started_at')->limit(15)])
            ->get()
            ->filter(fn (IntegrationConnection $connection) => Connectors::exists($connection->connector));

        return inertia('Admin/Integrations/IndexPage', [
            'connectors' => array_values(array_map(fn (string $class) => $class::describe(), Connectors::all())),
            'connections' => $connections->map(fn (IntegrationConnection $connection) => $this->present($connection))->values(),
            'areas' => array_map(fn (Area $area) => [
                'key' => $area->value,
                'label' => $area->label(),
                'synced' => in_array($area, Area::synced(), true),
            ], Area::cases()),
        ]);
    }

    public function store(IntegrationStoreRequest $request): RedirectResponse
    {
        $connection = IntegrationConnection::open($request->validated('connector'));
        $connection->configure($request->validated());
        $connection->save();

        return back()->with('success', $connection->label() . ' is gekoppeld. Test de verbinding en kies wat er over en weer gaat.');
    }

    public function update(IntegrationUpdateRequest $request, IntegrationConnection $integration): RedirectResponse
    {
        $integration->configure($request->validated());
        $integration->save();

        return back()->with('success', 'Koppeling met ' . $integration->label() . ' opgeslagen.');
    }

    public function destroy(IntegrationManageRequest $request, IntegrationConnection $integration, DisconnectIntegrationAction $disconnect): RedirectResponse
    {
        $label = $integration->label();
        $disconnect->execute($integration);

        return redirect()->route('integrations.index')->with('success', 'Koppeling met ' . $label . ' verwijderd.');
    }

    public function test(IntegrationManageRequest $request, IntegrationConnection $integration): RedirectResponse
    {
        $integration->connector()->test();

        return back()->with('success', 'De verbinding met ' . $integration->label() . ' werkt.');
    }

    public function forgetCredential(IntegrationManageRequest $request, IntegrationConnection $integration, string $key): RedirectResponse
    {
        $known = array_map(fn (Field $field) => $field->key, $integration->connectorClass()::credentialFields());
        abort_unless(in_array($key, $known, true), 404);

        $integration->forgetCredential($key);
        $integration->save();

        return back()->with('success', 'Sleutel gewist.');
    }

    /**
     * By hand, from the list of customers, materials or ledger accounts, or
     * from this screen. Runs while
     * you wait, in one transaction: after a refusal nothing has changed, and
     * the message says why. As a batch: a first pull brings in every record
     * there is, more than a request may otherwise announce.
     */
    public function pull(IntegrationPullRequest $request, IntegrationConnection $integration, string $area, Synchronizer $synchronizer): RedirectResponse
    {
        $run = Signals::asBatch(fn () => $synchronizer->pull($integration, Area::from($area)));

        return back()->with('success', 'Uit ' . $integration->label() . ': ' . $run->summary() . '.');
    }

    /** @return array<string, mixed> */
    private function present(IntegrationConnection $connection): array
    {
        $class = $connection->connectorClass();
        $credentials = $connection->credentials();
        $areas = collect(Area::cases())->mapWithKeys(fn (Area $area) => [$area->morphClass() => $area->value]);

        return [
            'id' => $connection->id,
            'connector' => $connection->connector,
            'label' => $class::label(),
            'is_active' => $connection->is_active,
            'settings' => [
                'options' => (object) ($connection->settings['options'] ?? []),
                'areas' => [
                    ...collect(Area::synced())->mapWithKeys(fn (Area $area) => [$area->value => [
                        'read' => (bool) $connection->areaSetting($area, 'read', false),
                        'write' => (bool) $connection->areaSetting($area, 'write', false),
                        'leading' => $connection->leading($area)->value,
                    ]]),
                    'service_orders' => ['form' => $connection->areaSetting(Area::ServiceOrders, 'form')],
                ],
            ],
            'signed_in' => !$class::connectsWithOAuth() || filled($credentials['access_token'] ?? null),
            'credentials' => array_map(fn (Field $field) => [
                'key' => $field->key,
                'stored' => filled($credentials[$field->key] ?? null),
                'value' => $field->secret ? null : ($credentials[$field->key] ?? null),
            ], $class::credentialFields()),
            'linked' => $connection->links()
                ->selectRaw('integrationable_type, count(*) as total')
                ->groupBy('integrationable_type')
                ->pluck('total', 'integrationable_type')
                ->mapWithKeys(fn ($total, $type) => [$areas[$type] ?? $type => $total]),
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'last_error_at' => $connection->last_error_at?->toIso8601String(),
            'runs' => $connection->runs->map(fn (IntegrationRun $run) => [
                'id' => $run->id,
                'area' => $run->area->label(),
                'direction' => $run->direction,
                'status' => $run->status,
                'summary' => $run->status === IntegrationRun::FAILED ? $run->message : $run->summary(),
                'message' => $run->status === IntegrationRun::FAILED ? null : $run->message,
                'started_at' => $run->started_at?->toIso8601String(),
            ]),
        ];
    }
}
