<template>
    <PanelSection title="AI bijkopen">

        <template #description>

            Eenmalig en niet aan een maand gebonden: wat er niet op gaat blijft staan.

            Het maandtegoed gaat er eerst af.<br>

            Tarief: {{ euro(ai.rate_cents) }} betaald geeft € 1,00 aan tegoed.

        </template>


        <form @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block font-semibold">Bedrag betaald (€)</label>
                    <input v-model="form.paid_euro" type="number" step="0.01" min="0.01" placeholder="10.00"
                        class="panel-field w-full">
                    <p v-if="form.errors.paid_euro" class="mt-1 font-semibold text-red-700">{{ form.errors.paid_euro }}</p>
                </div>
                <div>
                    <label class="mb-1 block font-semibold">Notitie</label>
                    <input v-model="form.note" type="text" placeholder="factuurnummer"
                        class="panel-field w-full">
                </div>
            </div>
            <p class="mt-3">
                <button type="submit" :disabled="form.processing"
                    class="panel-button">Toevoegen</button>
            </p>
        </form>

        <table v-if="topups.length" class="mt-3 w-full text-left">
            <thead class="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-1.5">Datum</th><th class="py-1.5">Betaald</th>
                    <th class="py-1.5">Tegoed</th><th class="py-1.5">Notitie</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="topup in topups" :key="topup.id" class="border-t border-slate-200">
                    <td class="py-1.5">{{ topup.date }}</td>
                    <td class="py-1.5">{{ euro(topup.paid_cents) }}</td>
                    <td class="py-1.5">{{ euro(topup.granted_cents) }}</td>
                    <td class="py-1.5 text-slate-500">{{ topup.note }}</td>
                </tr>
            </tbody>
        </table>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { useForm } from '@inertiajs/vue3'
import { euro } from './money.js'

const props = defineProps({
    tenant: { type: Object, required: true },
    ai: { type: Object, required: true },
    topups: { type: Array, required: true },
})

const form = useForm({ paid_euro: '', note: '' })

const submit = () => form.post(`/beheer/${props.tenant.id}/bijkoop`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
})
</script>
