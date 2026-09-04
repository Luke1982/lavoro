<template>
    <p class="mb-3 text-sm text-slate-500">
        {{ rows.length }} tenants &middot; samen {{ euro(monthly) }} per maand
    </p>

    <table class="w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-left">
        <thead class="bg-slate-100 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2.5">Naam</th>
                <th class="px-3 py-2.5">Pakket</th>
                <th class="px-3 py-2.5">Buiten</th>
                <th class="px-3 py-2.5">Binnen</th>
                <th class="px-3 py-2.5">Opslag</th>
                <th class="px-3 py-2.5">Per maand</th>
                <th class="px-3 py-2.5"></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.id" class="border-t border-slate-200">
                <td class="px-3 py-2.5">
                    <strong>{{ row.name }}</strong><br>
                    <span class="text-slate-500">{{ row.database }}</span>
                    <span v-if="row.busy" class="block text-slate-500">wordt nu aangemaakt…</span>
                    <span v-if="row.broken" class="block font-semibold text-red-700">{{ row.broken }}</span>
                </td>
                <td class="px-3 py-2.5">{{ row.package ?? '—' }}</td>
                <td class="px-3 py-2.5" :class="over(row.field, row.field_limit)">
                    {{ row.field }}/{{ row.field_limit }}
                </td>
                <td class="px-3 py-2.5" :class="over(row.office, row.office_limit)">
                    {{ row.office }}/{{ row.office_limit }}
                </td>
                <td class="px-3 py-2.5" :class="over(row.used_gb, row.storage_limit_gb)">
                    {{ row.used_gb }} / {{ row.storage_limit_gb }} GB
                </td>
                <td class="px-3 py-2.5">{{ euro(row.total) }}</td>
                <td class="px-3 py-2.5 text-right">
                    <Link :href="`/beheer/${row.id}`" class="text-blue-700 underline">bewerken</Link>
                </td>
            </tr>
        </tbody>
    </table>

    <ProvisioningQueue :requests="requests" />

    <GeneratedPasswords :passwords="passwords" />

    <NewTenantForm :packages="packages" :modules="modules" />
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { onBeforeUnmount, watch } from 'vue'
import ProvisioningQueue from '@/Components/Landlord/ProvisioningQueue.vue'
import GeneratedPasswords from '@/Components/Landlord/GeneratedPasswords.vue'
import NewTenantForm from '@/Components/Landlord/NewTenantForm.vue'

const props = defineProps({
    rows: { type: Array, required: true },
    monthly: { type: Number, required: true },
    requests: { type: Array, required: true },
    passwords: { type: Array, required: true },
    packages: { type: Array, required: true },
    modules: { type: Array, required: true },
})

const euro = (cents) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' })
    .format((cents ?? 0) / 100)

const over = (used, limit) => (used > limit ? 'font-semibold text-red-700' : '')

/**
 * Zolang de provisioner bezig is haalt het scherm zichzelf op.
 *
 * Met een gedeeltelijke herlading: Inertia vraagt alleen deze eigenschappen
 * opnieuw en vervangt ze, zonder de pagina te herladen. Daardoor blijft
 * ingetypte tekst in het formulier staan en is er geen tweede route nodig die
 * moet weten wanneer er iets veranderd is -- de server stuurt gewoon de
 * huidige stand.
 */
let timer = null

const stop = () => {
    clearInterval(timer)
    timer = null
}

const refresh = () => router.reload({
    only: ['rows', 'requests', 'passwords', 'monthly'],
    preserveScroll: true,
    preserveState: true,
})

const follow = (requests) => {
    const busy = requests.some((request) => ['queued', 'running'].includes(request.status))

    if (busy && !timer) {
        timer = setInterval(refresh, 3000)
    }

    if (!busy && timer) {
        stop()
    }
}

watch(() => props.requests, follow, { immediate: true, deep: true })

onBeforeUnmount(stop)
</script>
