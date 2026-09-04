<template>
    <section v-if="requests.length" class="mt-6 rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mt-0 text-base font-semibold">Bezig</h3>
        <p class="mb-3 text-sm text-slate-500">
            Aanmaken en verwijderen doet de provisioner op de achtergrond. Blijft een
            regel hier staan, dan draait die worker niet.
        </p>

        <table class="w-full text-left">
            <tr v-for="request in requests" :key="request.id" class="border-t border-slate-200">
                <td class="w-28 px-3 py-2.5 align-top">
                    <span v-if="request.status === 'failed'" class="font-semibold text-red-700">mislukt</span>
                    <span v-else class="text-slate-500">
                        {{ request.status === 'running' ? 'bezig' : 'in de wacht' }}
                    </span>
                </td>
                <td class="px-3 py-2.5">
                    {{ request.action === 'delete' ? 'Verwijderen' : 'Aanmaken' }}:
                    <strong>{{ request.name }}</strong>
                    <span v-if="request.error" class="block font-semibold text-red-700">{{ request.error }}</span>
                </td>
                <td class="w-28 px-3 py-2.5 text-right align-top">
                    <button v-if="request.status === 'failed'" type="button"
                        class="text-blue-700 underline" @click="dismiss(request)">weghalen</button>
                </td>
            </tr>
        </table>
    </section>
</template>

<script setup>
import { router } from '@inertiajs/vue3'

defineProps({ requests: { type: Array, required: true } })

/**
 * Een mislukte aanvraag blijft staan tot iemand hem weghaalt -- anders verdwijnt
 * de reden waarom het misging voordat iemand hem gelezen heeft.
 */
const dismiss = (request) => router.delete(`/beheer/aanvraag/${request.id}`, {
    preserveScroll: true,
})
</script>
