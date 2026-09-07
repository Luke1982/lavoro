<template>
    <PanelSection>
        <template #title>
            {{ row.reseller.name }}
            <span class="font-normal text-slate-500">
                {{ row.reseller.email }} &middot; {{ row.reseller.commission_percent }}% commissie
            </span>
        </template>

        <template #description>
            {{ row.tenants.length }} klant(en) &middot;
            <strong class="text-slate-900">{{ euro(row.commission) }} commissie per maand</strong>
        </template>

        <table class="w-full text-left">
            <thead class="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2 pr-3">Code</th>
                    <th class="py-2 pr-3">Korting</th>
                    <th class="py-2 pr-3">Looptijd</th>
                    <th class="py-2">Gebruikt door</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="coupon in row.coupons" :key="coupon.id" class="border-t border-slate-200">
                    <td class="py-2 pr-3"><code>{{ coupon.code }}</code></td>
                    <td class="py-2 pr-3">{{ coupon.discount_percent }}%</td>
                    <td class="py-2 pr-3">{{ coupon.discount_months }} maanden</td>
                    <td class="py-2">
                        <span v-if="coupon.redeemed_by_tenant_id" class="text-slate-500">
                            {{ tenantName(coupon.redeemed_by_tenant_id) }} op {{ nlDate(coupon.redeemed_at) }}
                        </span>
                        <strong v-else class="text-green-700">vrij</strong>
                    </td>
                </tr>
                <tr v-if="!row.coupons.length">
                    <td colspan="4" class="py-2 text-slate-500">Nog geen coupons.</td>
                </tr>
            </tbody>
        </table>

        <form class="mt-4 border-t border-slate-200 pt-4" @submit.prevent="submit">
            <div class="grid gap-3 md:grid-cols-4">
                <div>
                    <label :for="`code-${row.reseller.id}`" class="mb-1 block font-semibold">
                        Code <span class="font-normal text-slate-500">(leeg = willekeurig)</span>
                    </label>
                    <input :id="`code-${row.reseller.id}`" v-model="form.code" type="text" placeholder="ZOMER2026"
                        class="panel-field w-full">
                    <p v-if="form.errors.code" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.code }}</p>
                </div>
                <div>
                    <label :for="`aantal-${row.reseller.id}`" class="mb-1 block font-semibold">Aantal</label>
                    <input :id="`aantal-${row.reseller.id}`" v-model.number="form.aantal" type="number" min="1" max="50"
                        class="panel-field w-full">
                    <p v-if="form.errors.aantal" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.aantal }}</p>
                </div>
                <div>
                    <label :for="`korting-${row.reseller.id}`" class="mb-1 block font-semibold">Korting (%)</label>
                    <input :id="`korting-${row.reseller.id}`" v-model.number="form.discount_percent" type="number" min="1" max="100"
                        class="panel-field w-full">
                    <p v-if="form.errors.discount_percent" class="mt-1 text-sm font-semibold text-red-700">
                        {{ form.errors.discount_percent }}
                    </p>
                </div>
                <div>
                    <label :for="`looptijd-${row.reseller.id}`" class="mb-1 block font-semibold">Looptijd (maanden)</label>
                    <input :id="`looptijd-${row.reseller.id}`" v-model.number="form.discount_months" type="number" min="1" max="60"
                        class="panel-field w-full">
                    <p v-if="form.errors.discount_months" class="mt-1 text-sm font-semibold text-red-700">
                        {{ form.errors.discount_months }}
                    </p>
                </div>
            </div>

            <button type="submit" :disabled="form.processing"
                class="panel-button mt-4">Coupons aanmaken</button>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { useForm } from '@inertiajs/vue3'
import { nlDate } from '@/Utilities/Utilities'
import { euro } from '@/Components/Landlord/money.js'

const props = defineProps({
    row: { type: Object, required: true },
})

const form = useForm({
    reseller_id: props.row.reseller.id,
    code: '',
    aantal: 1,
    discount_percent: 10,
    discount_months: 12,
})

/** De klant staat in de lijst van deze reseller; anders blijft het id over. */
const tenantName = (id) => props.row.tenants.find((tenant) => tenant.id === id)?.name ?? id

const submit = () => form.post('/beheer/coupons', {
    preserveScroll: true,
    onSuccess: () => form.reset('code'),
})
</script>
