<template>
    <h2 class="mb-1 text-xl font-semibold">{{ tenant.name }}</h2>
    <p class="mb-5 text-sm text-slate-500">{{ tenant.database }} &middot; {{ tenant.id }}</p>

    <div class="grid gap-5 lg:grid-cols-2 lg:items-start">
        <SubscriptionForm :tenant="tenant" :packages="packages" :modules="modules"
            :ai="ai" :subscription="subscription" />

        <div class="grid gap-5">
            <BillingCard :tenant="tenant" :billing="billing" />
            <CouponCard :tenant="tenant" :reseller="reseller" :subscription="subscription" />
            <AiTopupCard :tenant="tenant" :ai="ai" :topups="topups" />
            <SuperAdminCard :tenant="tenant" :superadmins="superadmins" />
        </div>
    </div>

    <div v-if="unreachable" class="mt-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">
        De database van deze klant is niet bereikbaar, dus een deel van dit scherm
        blijft leeg: <span class="font-semibold">{{ unreachable }}</span><br>
        Hieronder kun je hem opruimen.
    </div>

    <DeleteTenantCard :tenant="tenant" />
</template>

<script setup>
import SubscriptionForm from '@/Components/Landlord/SubscriptionForm.vue'
import BillingCard from '@/Components/Landlord/BillingCard.vue'
import CouponCard from '@/Components/Landlord/CouponCard.vue'
import AiTopupCard from '@/Components/Landlord/AiTopupCard.vue'
import SuperAdminCard from '@/Components/Landlord/SuperAdminCard.vue'
import DeleteTenantCard from '@/Components/Landlord/DeleteTenantCard.vue'

defineProps({
    tenant: { type: Object, required: true },
    packages: { type: Array, required: true },
    modules: { type: Array, required: true },
    ai: { type: Object, required: true },
    topups: { type: Array, required: true },
    subscription: { type: Object, required: true },
    billing: { type: Object, required: true },
    reseller: { type: Object, default: null },
    superadmins: { type: Array, required: true },
    unreachable: { type: String, default: null },
})
</script>
