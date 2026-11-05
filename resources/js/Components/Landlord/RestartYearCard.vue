<template>
    <section class="mt-6 rounded-lg border border-red-300 bg-white p-5">
        <h3 class="mt-0 mb-2 text-base font-semibold text-red-700">{{ numbering.year }} opnieuw beginnen</h3>

        <p class="text-sm text-slate-500">
            Dit verwijdert alle {{ numbering.issued }} facturen van {{ numbering.year }}, creditfacturen
            meegerekend, en de volgende factuur wordt weer {{ numbering.prefix }}1. Wat ze verrekenden
            (bijgekocht AI-tegoed, verrekeningen) staat daarna weer open. Het automatisch factureren
            maakt de facturen van de lopende periode daarna opnieuw aan. Er is geen weg terug.
        </p>

        <p v-if="reached.length" class="mt-2 text-sm font-semibold text-red-700">
            Al de deur uit: {{ reached.join(', ') }}. Die heeft een klant, de bank of de boekhouding al;
            daar zet je ze zelf recht. Een boeking die in e-Boekhouden blijft staan, botst met de nieuwe
            factuur met hetzelfde nummer.
        </p>

        <form class="mt-3" @submit.prevent="submit">
            <div class="grid items-end gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block font-semibold">Tik {{ numbering.year }} over om te bevestigen</label>
                    <input v-model="form.confirm_year" type="text" autocomplete="off"
                        :placeholder="String(numbering.year)" class="panel-field w-full">
                    <p v-if="form.errors.confirm_year" class="mt-1 font-semibold text-red-700">
                        {{ form.errors.confirm_year }}
                    </p>
                </div>
                <div>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-md bg-red-700 px-4 py-2 text-white disabled:opacity-60">
                        Alle facturen van {{ numbering.year }} verwijderen
                    </button>
                </div>
            </div>
        </form>
    </section>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({ numbering: { type: Object, required: true } })

const form = useForm({ confirm_year: '' })

const reached = computed(() => [
    props.numbering.mailed && `${props.numbering.mailed} gemaild`,
    props.numbering.collected && `${props.numbering.collected} geïncasseerd`,
    props.numbering.booked && `${props.numbering.booked} naar e-Boekhouden`,
].filter(Boolean))

const submit = () => form.post('/beheer/facturatie/opnieuw-beginnen', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
})
</script>
