<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\DestroyProvisioningRequestRequest;
use App\Http\Requests\Landlord\DestroyTenantRequest;
use App\Http\Requests\Landlord\LandlordStatusRequest;
use App\Http\Requests\Landlord\StoreTenantRequest;
use App\Http\Requests\Landlord\UpdateTenantRequest;
use App\Jobs\RunTenantProvisioningRequestJob;
use App\Models\Central\AiTopup;
use App\Models\Central\Module;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Central\Reseller;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicer;
use App\Services\StorageQuota;
use App\Services\TenantSubscription;
use App\Services\TenantSuperAdmins;
use App\Support\Money;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * De klanten zelf: overzicht, abonnement, aanmaken en verwijderen.
 */
class TenantController extends Controller
{
    public function index()
    {
        $being_created = TenantProvisioningRequest::on('central')
            ->where('action', 'create')
            ->whereIn('status', ['queued', 'running'])
            ->pluck('name');

        $rows = Tenant::on('central')->orderBy('name')->get()->map(function (Tenant $tenant) use ($being_created) {
            $package = Package::on('central')->where('key', $tenant->package_key)->first();

            /**
             * Through the helper: if it blows up on one customer, that tenant
             * stays open and the next round counts in the previous customer's
             * database.
             *
             * And one broken customer must not take the list down with it. If
             * the creation is still running the tables are simply not there
             * yet; that is not an error but a moment.
             */
            try {
                [$field, $office, $used] = Tenancy::within($tenant, fn () => [
                    User::occupyingSeat('field')->count(),
                    User::occupyingSeat('office')->count(),
                    (new StorageQuota)->usedBytes(),
                ]);
                $broken = null;
            } catch (\Throwable $e) {
                [$field, $office, $used] = [0, 0, 0];
                $broken = $being_created->contains($tenant->name) ? null : $e->getMessage();
            }

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'database' => $tenant->getInternal('db_name'),
                'package' => $tenant->package_key,
                'busy' => $being_created->contains($tenant->name),
                'broken' => $broken,
                'field' => $field,
                'field_limit' => (int) ($package->field_seats ?? 0) + (int) $tenant->extra_field_seats,
                'office' => $office,
                'office_limit' => (int) ($package->office_seats ?? 0) + (int) $tenant->extra_office_seats,
                'used_gb' => round($used / (1024 ** 3), 2),
                'storage_limit_gb' => (int) $tenant->storage_limit_gb,
                'total' => (new TenantSubscription($tenant))->monthlyTotalCents(),
                /** Without a start date nothing is ever invoiced for this customer. */
                'starts_on' => $tenant->subscription_started_on,
            ];
        })->values();

        return inertia('Landlord/IndexPage', [
            'rows' => $rows,
            'monthly' => $rows->sum('total'),
            'packages' => Package::on('central')->orderBy('sort_order')
                ->get(['key', 'name', 'price_cents']),
            'modules' => Module::on('central')->orderBy('sort_order')
                ->get(['key', 'name', 'price_cents']),
            /** Work still running or gone wrong. Work that succeeded is the tenant itself. */
            'requests' => TenantProvisioningRequest::on('central')
                ->whereIn('status', ['queued', 'running', 'failed'])
                ->orderByDesc('id')
                ->get(['id', 'action', 'status', 'name', 'error']),
            /**
             * Passwords still waiting to be collected. Only from customers that
             * still exist: the password of a deleted customer should not be
             * anywhere any more.
             */
            'passwords' => TenantProvisioningRequest::on('central')
                ->whereNotNull('generated_password')
                ->whereIn('tenant_id', Tenant::on('central')->pluck('id'))
                ->orderByDesc('id')
                ->get(['id', 'name', 'email', 'generated_password'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'password' => $row->generated_password,
                ]),
        ]);
    }

    public function edit(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $spent = (int) DB::connection('central')->table('assistant_usage')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');

        $allowance = (int) ($tenant->ai_allowance_micros
            ?? PricingSetting::value('ai_allowance_micros', 12_500_000));

        /**
         * If the database of this customer cannot be reached -- half created,
         * half cleaned up -- this screen should work all the more: it holds the
         * button that clears such a customer away.
         */
        $unreachable = null;
        $superadmins = [];

        try {
            $superadmins = app(TenantSuperAdmins::class)->all($tenant);
        } catch (\Throwable $e) {
            $unreachable = $e->getMessage();
        }

        $subscription = new TenantSubscription($tenant);
        $invoicer = new Invoicer($tenant);
        $reseller = $tenant->reseller_id ? Reseller::on('central')->find($tenant->reseller_id) : null;

        return inertia('Landlord/EditPage', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'database' => $tenant->getInternal('db_name'),
                /**
                 * Unedited to the screen, like the other two dates below. The
                 * column is a date, so this is already yyyy-mm-dd -- exactly
                 * what a date field wants. There used to be an
                 * optional()->format() around it, and optional() on text rather
                 * than an object yields null: the field always came back empty,
                 * and the very first save wrote that emptiness back to the
                 * database. That lost the start date and left nothing to
                 * invoice for that customer.
                 */
                'subscription_started_on' => $tenant->subscription_started_on,
                'subscription_ends_on' => $tenant->subscription_ends_on,
                'billing_period' => $tenant->billing_period,
                'package_key' => $tenant->package_key,
                'extra_field_seats' => (int) $tenant->extra_field_seats,
                'extra_office_seats' => (int) $tenant->extra_office_seats,
                'storage_limit_gb' => (int) $tenant->storage_limit_gb,
                'modules' => $tenant->modules ?? [],
                /** As an object, so empty does not become a list the screen looks up keys in. */
                'module_prices' => (object) ($tenant->module_prices ?? []),
                'discount_cents' => (int) $tenant->discount_cents,
                'discount_percent' => (int) $tenant->discount_percent,
                'price_override_cents' => $tenant->price_override_cents,
                'invoice_address' => $tenant->invoice_address,
                'invoice_email' => $tenant->invoice_email,
                'invoice_postcode' => $tenant->invoice_postcode,
                'invoice_city' => $tenant->invoice_city,
                'vat_number' => $tenant->vat_number,
                'coc_number' => $tenant->coc_number,
                'payment_method' => $tenant->payment_method,
                'iban' => $tenant->iban,
                'account_holder' => $tenant->account_holder,
                'mandate_reference' => $tenant->mandate_reference,
                'mandate_signed_on' => $tenant->mandate_signed_on,
                'coupon_discount_percent' => (int) $tenant->coupon_discount_percent,
                'coupon_discount_until' => $tenant->coupon_discount_until,
            ],
            'packages' => Package::on('central')->orderBy('sort_order')->get(['key', 'name', 'price_cents']),
            'modules' => Module::on('central')->orderBy('sort_order')->get(['key', 'name', 'price_cents']),
            'ai' => [
                /** In cents to the screen: the screen converted itself, in three places slightly differently. */
                'spent_cents' => Money::fromMicros($spent),
                'allowance_cents' => Money::fromMicros($allowance),
                'is_default' => $tenant->ai_allowance_micros === null,
                'topup_cents' => Money::fromMicros((int) AiTopup::on('central')
                    ->where('tenant_id', $tenant->id)->sum('granted_micros')),
                'rate_cents' => (int) PricingSetting::value('ai_topup_cents_per_euro_granted', 200),
            ],
            'topups' => AiTopup::on('central')->where('tenant_id', $tenant->id)->latest()->get()
                ->map(fn ($topup) => [
                    'id' => $topup->id,
                    'date' => $topup->created_at->format('d-m-Y'),
                    'paid_cents' => (int) $topup->paid_cents,
                    'granted_cents' => Money::fromMicros((int) $topup->granted_micros),
                    'note' => $topup->note,
                ]),
            'subscription' => [
                'before_discount_cents' => $subscription->beforeDiscountCents(),
                'discount_cents' => $subscription->discountCents(),
                'total_cents' => $subscription->monthlyTotalCents(),
                'commission_cents' => $subscription->commissionCents(),
            ],
            'billing' => [
                'next_cents' => $invoicer->preview()['total_cents'],
                'pending' => $invoicer->pendingCharges()
                    ->map(fn ($charge) => [
                        'description' => $charge->description,
                        'amount_cents' => (int) $charge->amount_cents,
                    ])->values(),
            ],
            'reseller' => $reseller ? [
                'name' => $reseller->name,
                'commission_percent' => (int) $reseller->commission_percent,
            ] : null,
            'superadmins' => $superadmins,
            'unreachable' => $unreachable,
        ]);
    }

    public function update(UpdateTenantRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        /**
         * Before and after, because a package change halfway through the month
         * produces a settlement for the next invoice.
         *
         * Only the package itself. What comes on top of it -- a module, a seat,
         * more storage, a price agreed for one of those -- simply travels along
         * with the next invoice and produces no settlement: that is an
         * extension and not a change, and a line working out how many days
         * someone already had that module makes the invoice unreadable over a
         * couple of euros.
         */
        $before = new TenantSubscription($tenant);
        $before_cents = $before->packageCents();
        $before_package = $before->packageName();
        $ended_on = $tenant->subscription_ends_on;

        $attributes = $request->tenantAttributes();
        $attributes['module_started_on'] = $this->moduleStartDates($tenant, $attributes['modules'] ?? []);

        /**
         * When the billing term changes, it starts at the first period that has
         * not been paid for -- counted by the old term, so before the change is
         * saved.
         */
        if (($attributes['billing_period'] ?? null) !== $tenant->billing_period) {
            $attributes['billing_period_started_on'] = (new Invoicer($tenant))->termStartsOn()->toDateString();
        }

        $tenant->update($attributes);

        $after = new TenantSubscription($tenant->refresh());
        $charge = (new Invoicer($tenant))->prorate(
            $before_cents,
            $after->packageCents(),
            old_package: $before_package,
            new_package: $after->packageName(),
        );

        $this->settleCancellation($tenant, $ended_on);

        return redirect()->route('landlord.edit', $tenant->id)->with(
            'status',
            $tenant->name . ' is bijgewerkt.' . ($charge
                ? ' Verrekening van € ' . Money::human($charge->amount_cents)
                    . ' staat klaar voor de volgende factuur.'
                : ''),
        );
    }

    /**
     * A cancellation halfway through an already paid period produces credit; a
     * withdrawn cancellation takes that credit away again.
     */
    private function settleCancellation(Tenant $tenant, ?string $ended_on): void
    {
        $ends_on = $tenant->subscription_ends_on;

        if ($ends_on === $ended_on) {
            return;
        }

        $invoicer = new Invoicer($tenant);

        $ends_on
            ? $invoicer->settleCancellation(CarbonImmutable::parse($ends_on))
            : $invoicer->forgetCancellationSettlement();
    }

    /**
     * Per module the day it was switched on.
     *
     * Modules that stay keep their date; one that goes loses it, so switching it
     * on again counts again. Without these dates there is no working out how
     * much of the current month someone had a module, and they pay a whole
     * month for something they added on the seventh.
     *
     * @param  array<int, string>  $modules
     * @return array<string, string>
     */
    private function moduleStartDates(Tenant $tenant, array $modules): array
    {
        $known = $tenant->module_started_on ?? [];
        $today = now()->toDateString();

        return collect($modules)
            ->mapWithKeys(fn (string $key) => [$key => $known[$key] ?? $today])
            ->all();
    }

    /**
     * A short summary of what is running, so the screen can refresh itself
     * while the provisioner is busy.
     *
     * No content, only a fingerprint: if it changes, something happened and the
     * page fetches itself again. That way this does not have to know what the
     * screen looks like, and there stays one place where that lives.
     */
    public function provisioningStatus(LandlordStatusRequest $request)
    {
        return response()->json([
            'signature' => $this->provisioningSignature(),
            'busy' => TenantProvisioningRequest::on('central')
                ->whereIn('status', ['queued', 'running'])->exists(),
        ]);
    }

    /**
     * What the screen watches. If this changes, something happened.
     *
     * The screen gets it while being built, so the first poll already has
     * something to compare against. Without that, that first poll had nothing
     * to measure by: work finished within a couple of seconds -- deleting
     * sometimes takes less than one -- was already over before a single poll,
     * and the screen stayed as it was built. Precisely the cases where you need
     * the refreshing most.
     */
    private function provisioningSignature(): string
    {
        $rows = TenantProvisioningRequest::on('central')
            ->orderBy('id')
            ->get(['id', 'status'])
            ->map(fn ($row) => $row->id . ':' . $row->status)
            ->implode(',');

        return md5($rows . '|' . Tenant::on('central')->count());
    }

    public function destroyProvisioningRequest(DestroyProvisioningRequestRequest $request, int $id)
    {
        TenantProvisioningRequest::on('central')
            ->where('id', $id)
            ->where('status', 'failed')
            ->delete();

        return back()->with('status', 'Mislukte aanvraag weggehaald.');
    }

    public function storeTenant(StoreTenantRequest $request)
    {
        $data = $request->validated();

        $provisioning = TenantProvisioningRequest::on('central')->create([
            'action' => 'create',
            'name' => $data['name'],
            'email' => $data['email'],
            'package_key' => $data['package_key'],
            'modules' => $data['modules'] ?? [],
        ]);

        RunTenantProvisioningRequestJob::dispatch($provisioning->id)->onQueue('provisioning');

        return back()->with('status', 'Aanvraag klaargezet. Zodra de provisioner klaar is verschijnt '
            . $data['name'] . ' in de lijst, met het wachtwoord erbij.');
    }

    /**
     * The panel may not drop a database -- the provisioner does that. Only the
     * request is put down here, after a name that matches literally.
     */
    public function destroyTenant(DestroyTenantRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $provisioning = TenantProvisioningRequest::on('central')->create([
            'action' => 'delete',
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
        ]);

        RunTenantProvisioningRequestJob::dispatch($provisioning->id)->onQueue('provisioning');

        return redirect()->route('landlord.index')
            ->with('status', $tenant->name . ' staat klaar om verwijderd te worden.');
    }
}
