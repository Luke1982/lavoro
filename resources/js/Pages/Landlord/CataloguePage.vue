<template>
    <Head title="Catalogus" />

    <h2 class="mb-1 text-xl font-semibold">Catalogus</h2>
    <p class="mb-5 text-sm text-slate-500">
        Wat er te koop is en tegen welke prijs. Bedragen in centen, zodat er niets
        wordt afgerond onderweg.
    </p>

    <PanelSection title="Pakketten" class="mb-6">

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2 pr-3">Sleutel</th>
                        <th class="py-2 pr-3">Naam</th>
                        <th class="py-2 pr-3">Buiten</th>
                        <th class="py-2 pr-3">Binnen</th>
                        <th class="py-2 pr-3">Prijs</th>
                        <th class="py-2 pr-3">Extra buiten</th>
                        <th class="py-2 pr-3">Extra binnen</th>
                        <th class="py-2 pr-3">In gebruik</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in packageRows" :key="row.id" class="border-t border-slate-200 align-top">
                        <td class="py-2 pr-3"><code class="text-slate-500">{{ row.key }}</code></td>
                        <td class="py-2 pr-3">
                            <input v-model="row.name" type="text" class="panel-field-narrow w-32">
                        </td>
                        <td class="py-2 pr-3">
                            <input v-model.number="row.field_seats" type="number" min="0" class="panel-field-narrow w-16">
                        </td>
                        <td class="py-2 pr-3">
                            <input v-model.number="row.office_seats" type="number" min="0" class="panel-field-narrow w-16">
                        </td>
                        <td class="py-2 pr-3">
                            <input v-model.number="row.price_cents" type="number" min="0" class="panel-field-narrow w-24">
                            <span class="mt-0.5 block text-xs text-slate-500">{{ euro(row.price_cents) }}</span>
                        </td>
                        <td class="py-2 pr-3">
                            <input v-model.number="row.extra_field_cents" type="number" min="0" class="panel-field-narrow w-24">
                            <span class="mt-0.5 block text-xs text-slate-500">{{ euro(row.extra_field_cents) }}</span>
                        </td>
                        <td class="py-2 pr-3">
                            <input v-model.number="row.extra_office_cents" type="number" min="0" class="panel-field-narrow w-24">
                            <span class="mt-0.5 block text-xs text-slate-500">{{ euro(row.extra_office_cents) }}</span>
                        </td>
                        <td class="py-2 pr-3 text-slate-500">{{ usage[row.key] ?? 0 }} klant(en)</td>
                        <td class="py-2 text-right">
                            <button type="button" @click="savePackage(row)"
                                class="panel-button-narrow">Opslaan</button>
                            <span v-if="errors[`package-${row.id}`]" class="mt-1 block text-xs font-semibold text-red-700">
                                {{ errors[`package-${row.id}`] }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PanelSection>

    <PanelSection title="Modules" class="mb-6">

        <table class="w-full text-left">
            <thead class="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2 pr-3">Sleutel</th>
                    <th class="py-2 pr-3">Naam</th>
                    <th class="py-2 pr-3">Prijs</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in moduleRows" :key="row.id" class="border-t border-slate-200 align-top">
                    <td class="py-2 pr-3"><code class="text-slate-500">{{ row.key }}</code></td>
                    <td class="py-2 pr-3">
                        <input v-model="row.name" type="text" class="panel-field-narrow w-48">
                    </td>
                    <td class="py-2 pr-3">
                        <input v-model.number="row.price_cents" type="number" min="0" class="panel-field-narrow w-24">
                        <span class="mt-0.5 block text-xs text-slate-500">{{ euro(row.price_cents) }}</span>
                    </td>
                    <td class="py-2 text-right">
                        <button type="button" @click="saveModule(row)"
                            class="panel-button-narrow">Opslaan</button>
                        <span v-if="errors[`module-${row.id}`]" class="mt-1 block text-xs font-semibold text-red-700">
                            {{ errors[`module-${row.id}`] }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </PanelSection>

    <PanelSection title="Bundels" class="mb-6">

        <table class="w-full text-left">
            <thead class="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2 pr-3">Naam</th>
                    <th class="py-2 pr-3">Modules</th>
                    <th class="py-2">Prijs</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="bundle in bundles" :key="bundle.id" class="border-t border-slate-200">
                    <td class="py-2 pr-3">{{ bundle.name }}</td>
                    <td class="py-2 pr-3 text-slate-500">{{ bundle.module_keys.join(', ') }}</td>
                    <td class="py-2">{{ euro(bundle.price_cents) }}</td>
                </tr>
                <tr v-if="!bundles.length">
                    <td colspan="3" class="py-2 text-slate-500">Nog geen bundels.</td>
                </tr>
            </tbody>
        </table>
    </PanelSection>

    <PanelSection title="Instellingen" class="mb-6">

        <table class="w-full text-left">
            <thead class="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2 pr-3">Sleutel</th>
                    <th class="py-2 pr-3">Waarde</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in settingRows" :key="row.id" class="border-t border-slate-200 align-top">
                    <td class="py-2 pr-3"><code class="text-slate-500">{{ row.key }}</code></td>
                    <td class="py-2 pr-3">
                        <input v-model.number="row.value" type="number" min="0" class="panel-field-narrow w-36">
                    </td>
                    <td class="py-2 text-right">
                        <button type="button" @click="saveSetting(row)"
                            class="panel-button-narrow">Opslaan</button>
                        <span v-if="errors[`setting-${row.id}`]" class="mt-1 block text-xs font-semibold text-red-700">
                            {{ errors[`setting-${row.id}`] }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </PanelSection>

    <PanelSection title="Facturatie" id="facturatie">
        <template #description>
            De afzendergegevens op de factuur en het incassocontract. Het incassant-ID
            krijg je van de bank; zonder dat nummer weigert de bank een incassobestand.
        </template>


        <form @submit.prevent="saveIssuer">
            <div class="grid gap-3 md:grid-cols-2">
                <div v-for="row in issuer_rows" :key="row.id">
                    <label class="mb-1 block font-semibold" :for="`issuer-${row.key}`">
                        <code class="font-mono text-xs text-slate-500">{{ row.key }}</code>
                    </label>
                    <input :id="`issuer-${row.key}`" v-model="issuerForm.issuer[row.key]" type="text"
                        class="panel-field w-full">
                    <p v-if="issuerForm.errors[`issuer.${row.key}`]" class="mt-1 text-sm font-semibold text-red-700">
                        {{ issuerForm.errors[`issuer.${row.key}`] }}
                    </p>
                </div>
            </div>

            <button type="submit" :disabled="issuerForm.processing"
                class="panel-button mt-4">Opslaan</button>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { euro } from '@/Components/Landlord/money.js'

const props = defineProps({
    packages: { type: Array, required: true },
    modules: { type: Array, required: true },
    bundles: { type: Array, required: true },
    settings: { type: Array, required: true },
    usage: { type: Object, required: true },
    issuer_rows: { type: Array, required: true },
})

/**
 * Elke regel is een eigen formuliertje, dus de velden staan hier los van de
 * props: de props zijn na het opslaan weer wat er in de database staat, en
 * daar moeten de velden dan ook weer op terug.
 */
const copy = (rows) => rows.map((row) => ({ ...row }))

const packageRows = ref(copy(props.packages))
const moduleRows = ref(copy(props.modules))
const settingRows = ref(copy(props.settings))

watch(() => props.packages, (rows) => { packageRows.value = copy(rows) })
watch(() => props.modules, (rows) => { moduleRows.value = copy(rows) })
watch(() => props.settings, (rows) => { settingRows.value = copy(rows) })

/** Per regel de eerste melding, zodat die naast de knop komt te staan. */
const errors = ref({})

const save = (key, url, data) => router.put(url, data, {
    preserveScroll: true,
    onError: (messages) => { errors.value[key] = Object.values(messages)[0] },
    onSuccess: () => { delete errors.value[key] },
})

const savePackage = (row) => save(`package-${row.id}`, `/beheer/pakket/${row.id}`, {
    name: row.name,
    field_seats: row.field_seats,
    office_seats: row.office_seats,
    price_cents: row.price_cents,
    extra_field_cents: row.extra_field_cents,
    extra_office_cents: row.extra_office_cents,
})

const saveModule = (row) => save(`module-${row.id}`, `/beheer/module/${row.id}`, {
    name: row.name,
    price_cents: row.price_cents,
})

const saveSetting = (row) => save(`setting-${row.id}`, `/beheer/instelling/${row.id}`, {
    value: row.value,
})

const issuerForm = useForm({
    issuer: Object.fromEntries(props.issuer_rows.map((row) => [row.key, row.value ?? ''])),
})

const saveIssuer = () => issuerForm.put('/beheer/facturatie', { preserveScroll: true })
</script>
