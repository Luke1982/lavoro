<template>
    <div class="space-y-6 pb-32">
        <BoxComponent>
            <SectionHeader :icon="BookmarkIcon" title="Opgeslagen koppeling"
                subtitle="Kies de koppeling van deze leverancier, of koppel de kolommen hieronder en sla ze op voor de volgende keer." />
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <ComboBox class="flex-1" :options="mappingOptions" :model-value="selectedMappingId"
                    placeholder="Kies een opgeslagen koppeling"
                    empty-message="Er zijn nog geen koppelingen opgeslagen."
                    @update:model-value="selectMapping" />
                <button v-if="selectedMappingId" type="button" @click="deleteMapping"
                    class="text-sm font-semibold text-red-600 dark:text-red-400 hover:underline sm:pb-2">
                    Koppeling verwijderen
                </button>
            </div>
            <p v-if="suggestionNote" class="mt-3 text-sm text-emerald-700 dark:text-emerald-400">{{ suggestionNote }}</p>
        </BoxComponent>

        <BoxComponent>
            <SectionHeader :icon="TableCellsIcon" title="Bestand"
                subtitle="Op welke regel staan de kolomnamen? Daaronder begint de lijst." />
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div v-if="sheet.names.length > 1">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">Tabblad</label>
                    <select v-model="form.sheet" @change="reloadColumns"
                        class="mt-2 block w-full rounded-md border-0 py-1.5 bg-white dark:bg-slate-900 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 sm:text-sm">
                        <option v-for="name in sheet.names" :key="name" :value="name">{{ name }}</option>
                    </select>
                </div>
                <TextInput v-model="form.header_row" type="number" :min="1" :max="1000" label="Kolomnamen op regel"
                    :has-error="!!errorFor('header_row')" :error-message="errorFor('header_row')"
                    @update:model-value="reloadColumns" />
            </div>
            <p v-if="!sheet.columns.length" class="mt-3 text-sm text-amber-700 dark:text-amber-400">
                Op deze regel en eronder staat niets.
            </p>
        </BoxComponent>

        <BoxComponent>
            <SectionHeader :icon="TruckIcon" title="Leverancier en prijs"
                subtitle="Met een leverancier worden de artikelen aan hem gekoppeld, met zijn artikelnummer. Daaraan worden ze de volgende keer herkend." />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <ComboBox :options="suppliers" v-model="form.supplier_id" label="Leverancier (niet verplicht)"
                    placeholder="Kies een leverancier" :has-error="!!errorFor('supplier_id')"
                    :error-message="errorFor('supplier_id')" />
                <div v-if="costField">
                    <TextInput v-model="form.markup_percent" type="number" :min="0" :step="0.1"
                        label="Opslag op de kostprijs in %" placeholder="Bijvoorbeeld 35"
                        :has-error="!!errorFor('markup_percent')" :error-message="errorFor('markup_percent')" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                        Voor regels zonder eigen verkoopprijs: verkoopprijs = {{ costField.label.toLowerCase() }} plus
                        dit percentage.
                    </p>
                </div>
            </div>
        </BoxComponent>

        <BoxComponent>
            <SectionHeader :icon="ArrowsRightLeftIcon" title="Kolommen koppelen"
                :subtitle="'Kies per veld de kolom uit het bestand. Een veld zonder kolom blijft leeg bij nieuwe ' + kind.plural + ' en ongemoeid bij bestaande.'" />
            <p v-if="errorFor('columns')" class="mb-4 text-sm text-red-600 dark:text-red-400">{{ errorFor('columns') }}</p>
            <div class="divide-y divide-gray-100 dark:divide-slate-800">
                <div v-for="field in visibleFields" :key="field.key"
                    class="grid grid-cols-1 lg:grid-cols-12 gap-3 py-4 first:pt-0 items-start">
                    <div class="lg:col-span-3">
                        <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {{ field.label }}<span v-if="field.required" class="text-red-500"> *</span>
                        </p>
                        <p class="text-xs text-gray-500 dark:text-slate-400">{{ fieldHint(field) }}</p>
                    </div>
                    <div class="lg:col-span-4">
                        <ComboBox :options="columnOptions" v-model="form.columns[field.key]"
                            placeholder="Geen kolom" :has-error="!!errorFor('columns.' + field.key)"
                            :error-message="errorFor('columns.' + field.key)">
                            <template #option="{ option, active }">
                                <div class="min-w-0">
                                    <p class="truncate">{{ option.name }}</p>
                                    <p v-if="option.samples.length" class="truncate text-xs"
                                        :class="active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400'">
                                        {{ option.samples.join(' · ') }}
                                    </p>
                                </div>
                            </template>
                        </ComboBox>
                        <p v-if="samplesFor(field.key)" class="mt-1 truncate text-xs text-gray-500 dark:text-slate-400">
                            Bijvoorbeeld: {{ samplesFor(field.key) }}
                        </p>
                    </div>
                    <div class="lg:col-span-3">
                        <ComboBox v-if="field.type === 'lookup'" :options="field.options" v-model="form.fixed[field.key]"
                            :placeholder="'Vaste ' + field.label.toLowerCase() + ' voor nieuwe'"
                            :has-error="!!errorFor('fixed.' + field.key)" :error-message="errorFor('fixed.' + field.key)" />
                    </div>
                    <div class="lg:col-span-2 lg:pt-2">
                        <label v-if="!field.supplier" class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                            <input type="checkbox" v-model="form.update[field.key]"
                                class="rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800 text-lavoro-blue focus:ring-lavoro-blue" />
                            Bestaande bijwerken
                        </label>
                    </div>
                </div>
            </div>
        </BoxComponent>

        <BoxComponent>
            <SectionHeader :icon="FingerPrintIcon" title="Herkennen aan"
                :subtitle="'Een regel die zo een bestaand ' + kind.noun + ' vindt, werkt dat bij in plaats van een nieuw te maken. Twee regels in het bestand die hierop gelijk zijn, gelden als dubbel: de tweede wordt overgeslagen.'" />
            <div class="space-y-3">
                <label v-for="option in matchKeys" :key="option.key" class="flex items-start gap-3 text-sm"
                    :class="matchKeyUsable(option.key) ? 'text-gray-900 dark:text-slate-100' : 'text-gray-400 dark:text-slate-500'">
                    <input type="checkbox" :value="option.key" v-model="form.match_keys" :disabled="!matchKeyUsable(option.key)"
                        class="mt-0.5 rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800 text-lavoro-blue focus:ring-lavoro-blue disabled:opacity-50" />
                    <span>
                        {{ option.label }}
                        <span v-if="!matchKeyUsable(option.key)" class="block text-xs">{{ matchKeyHint(option.key) }}</span>
                    </span>
                </label>
            </div>
            <p class="mt-4 text-xs text-gray-500 dark:text-slate-400">
                {{ matchSummary }}
            </p>
            <p v-if="errorFor('match_keys')" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ errorFor('match_keys') }}</p>
        </BoxComponent>

        <BoxComponent>
            <SectionHeader :icon="BookmarkIcon" title="Koppeling bewaren"
                subtitle="Onder een naam bewaard, staat deze koppeling de volgende keer bovenaan klaar. Een bestaande naam wordt overschreven." />
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <TextInput class="flex-1" v-model="saveName" label="Naam" placeholder="Bijvoorbeeld de naam van de leverancier"
                    :has-error="!!saveForm.errors.name" :error-message="saveForm.errors.name" />
                <button type="button" @click="saveMapping" :disabled="saveForm.processing"
                    class="px-3 py-2 text-sm font-semibold rounded-md ring-1 ring-inset ring-gray-300 dark:ring-slate-600 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-50">
                    {{ saveForm.processing ? 'Bezig…' : 'Koppeling opslaan' }}
                </button>
            </div>
        </BoxComponent>

        <BottomActionBar>
            <span class="text-gray-500 dark:text-slate-400">Nog niets opgeslagen: eerst zie je wat er gaat gebeuren.</span>
            <template #actions>
                <button type="button" @click="emit('cancel')"
                    class="px-3 py-2 text-sm font-semibold text-gray-600 dark:text-slate-300 hover:underline">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing || !sheet.columns.length"
                    class="px-4 py-2 text-sm font-semibold rounded-md bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50">
                    {{ form.processing ? 'Bezig met lezen…' : 'Controleren' }}
                </button>
            </template>
        </BottomActionBar>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { debounce } from 'lodash'
import { ArrowsRightLeftIcon, BookmarkIcon, FingerPrintIcon, TableCellsIcon, TruckIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import BottomActionBar from '@/Components/UI/BottomActionBar.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import TextInput from '@/Components/UI/TextInput.vue'

const props = defineProps({
    importPath: { type: String, required: true },
    kind: { type: Object, required: true },
    settings: { type: Object, required: true },
    sheet: { type: Object, required: true },
    fields: { type: Array, required: true },
    matchKeys: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    mappings: { type: Array, required: true },
    suggestedMappingId: { type: Number, default: null },
})

const emit = defineEmits(['mapped', 'cancel'])

const fieldKeys = props.fields.map(field => field.key)

const costField = props.fields.find(field => field.is_cost) ?? null

const onlyKnown = (object) => Object.fromEntries(
    Object.entries(object ?? {}).filter(([key]) => fieldKeys.includes(key))
)

const updateFlags = (saved) => Object.fromEntries(
    props.fields.map(field => [field.key, saved?.[field.key] ?? field.updates])
)

const formState = (settings) => ({
    sheet: props.sheet.names.includes(settings.sheet) ? settings.sheet : props.sheet.name,
    header_row: settings.header_row ?? 1,
    supplier_id: settings.supplier_id ?? null,
    markup_percent: costField ? (settings.markup_percent ?? '') : '',
    columns: { ...Object.fromEntries(fieldKeys.map(key => [key, null])), ...onlyKnown(settings.columns) },
    fixed: onlyKnown(settings.fixed),
    update: updateFlags(settings.update),
    match_keys: settings.match_keys ?? props.matchKeys.map(option => option.key),
})

const form = useForm(formState(props.settings))
const saveForm = useForm({})
const saveName = ref('')
const selectedMappingId = ref(null)
const suggestionNote = ref('')

const mappingOptions = computed(() => props.mappings.map(mapping => ({ id: mapping.id, name: mapping.name })))

const columnOptions = computed(() => props.sheet.columns.map(column => ({
    id: column.name,
    name: column.name,
    samples: column.samples,
    search: column.samples.join(' '),
})))

const visibleFields = computed(() => props.fields.filter(field => !field.supplier || form.supplier_id))

function matchKeyUsable(key) {
    if (key === 'article_number' && !form.supplier_id) return false

    return Boolean(form.columns[key])
}

function matchKeyHint(key) {
    if (key === 'article_number' && !form.supplier_id) return 'Kies eerst een leverancier.'

    return 'Er is geen kolom voor gekoppeld.'
}

const activeMatchLabels = computed(() => props.matchKeys
    .filter(option => form.match_keys.includes(option.key) && matchKeyUsable(option.key))
    .map(option => option.label.toLowerCase()))

const matchSummary = computed(() => {
    const labels = activeMatchLabels.value

    if (!labels.length) return `Niets aangevinkt: elke regel wordt een nieuw ${props.kind.noun}.`
    if (labels.length === 1) return `Er wordt alleen op ${labels[0]} gezocht.`

    return `Er wordt eerst op ${labels[0]} gezocht; vindt dat niets, dan op ${labels.slice(1).join(', daarna op ')}.`
})

function fieldHint(field) {
    if (field.supplier) return 'Zo kent de leverancier het artikel.'
    if (field.is_primary) return 'Nodig voor nieuwe ' + props.kind.plural + '.'
    if (field.type === 'lookup') return 'Bestaat de naam nog niet, dan wordt hij aangemaakt.'
    if (field.type === 'price') return 'Bedragen als 12,50 of € 1.234,50 worden goed gelezen.'
    return ''
}

function samplesFor(key) {
    const column = props.sheet.columns.find(column => column.name === form.columns[key])

    return column?.samples.length ? column.samples.join(' · ') : ''
}

function errorFor(key) {
    return form.errors[key] ?? saveForm.errors[key] ?? ''
}

const reloadColumns = debounce(() => {
    if (!form.header_row || form.header_row < 1) return

    router.reload({
        data: { header_row: form.header_row, sheet: form.sheet },
        only: ['sheet'],
        preserveState: true,
        preserveScroll: true,
    })
}, 400)

function applyMapping(mapping) {
    const fresh = formState(mapping.settings)
    const reload = fresh.header_row !== Number(form.header_row) || fresh.sheet !== form.sheet

    Object.assign(form, fresh)
    form.clearErrors()
    saveForm.clearErrors()
    saveName.value = mapping.name
    selectedMappingId.value = mapping.id

    if (reload) reloadColumns()
}

function selectMapping(id) {
    suggestionNote.value = ''

    if (id === null) {
        selectedMappingId.value = null
        saveName.value = ''
        return
    }

    const mapping = props.mappings.find(candidate => candidate.id === id)

    if (mapping) applyMapping(mapping)
}

function deleteMapping() {
    const mapping = props.mappings.find(candidate => candidate.id === selectedMappingId.value)

    if (!mapping || !confirm(`Koppeling ‘${mapping.name}’ verwijderen?`)) return

    router.delete(`/import-mappings/${mapping.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            selectedMappingId.value = null
            saveName.value = ''
        },
    })
}

function saveMapping() {
    saveForm
        .transform(() => ({ ...form.data(), kind: props.kind.value, name: saveName.value }))
        .post('/import-mappings', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const saved = props.mappings.find(mapping => mapping.name.toLowerCase() === saveName.value.trim().toLowerCase())
                selectedMappingId.value = saved?.id ?? null
            },
        })
}

function submit() {
    saveForm.clearErrors()
    form.post(`${props.importPath}/mapping`, {
        preserveScroll: true,
        onSuccess: () => emit('mapped'),
    })
}

onMounted(() => {
    const hasColumns = Object.values(props.settings.columns ?? {}).some(Boolean)
    const suggested = props.mappings.find(mapping => mapping.id === props.suggestedMappingId)

    if (!hasColumns && suggested) {
        applyMapping(suggested)
        suggestionNote.value = `De koppeling ‘${suggested.name}’ past op dit bestand en is ingevuld.`
    }
})
</script>
