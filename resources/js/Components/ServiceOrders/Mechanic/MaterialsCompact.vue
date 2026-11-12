<template>
    <BoxComponent v-if="canSee" padding="p-4 sm:p-5">
        <SectionHeader :icon="Package" title="Materialen" chapter="materials">
            <template v-if="canAdd" #actions>
                <button type="button" :aria-expanded="adding" @click="adding = !adding"
                    class="inline-flex items-center gap-1.5 rounded-lavoro-sm px-3 py-2 text-sm font-semibold ring-1 transition-colors"
                    :class="adding
                        ? 'bg-lavoro-blue text-white ring-lavoro-blue'
                        : 'text-lavoro-blue ring-gray-200 hover:bg-gray-50 dark:text-sky-300 dark:ring-slate-700 dark:hover:bg-slate-800'">
                    <PlusIcon class="size-4 transition-transform duration-200" :class="adding ? 'rotate-45' : ''" />
                    <span class="sm:hidden">Toevoegen</span>
                    <span class="hidden sm:inline">Materiaal toevoegen</span>
                </button>
            </template>
        </SectionHeader>

        <div v-auto-animate>
            <div v-if="adding && canAdd"
                class="mb-3 flex flex-col gap-4 rounded-lavoro-sm bg-gray-50 p-3 dark:bg-slate-800/60">
                <MaterialPicker v-if="canCreate" :endpoint="base" :all-materials="allMaterials"
                    :materials-use-ajax="materialsUseAjax" :categories="categories" :usage-units="usageUnits"
                    :show-unforseen="showUnforseen" stacked />
                <FreeformMaterialForm v-if="canCreateFreeform" :endpoint="base" :show-unforseen="showUnforseen"
                    stacked :class="canCreate ? 'border-t border-gray-200 pt-4 dark:border-slate-700' : ''" />
            </div>
        </div>

        <p v-if="sentToAdministration"
            class="mb-3 rounded border border-amber-400 bg-amber-50 p-3 text-sm font-semibold text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            Deze order is naar de administratie verzonden. Materialen kunnen niet meer worden aangepast.
        </p>

        <ul v-if="rows.length" class="divide-y divide-gray-200/70 rounded-lavoro-sm ring-1 ring-gray-200/70 dark:divide-slate-700 dark:ring-slate-700">
            <li v-for="row in rows" :key="row.key" v-auto-animate>
                <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left"
                    :aria-expanded="openKey === row.key" @click="toggle(row)">
                    <span class="flex size-8 flex-none items-center justify-center rounded-md transition-colors"
                        :class="openKey === row.key ? 'bg-lavoro-blue text-white' : 'bg-indigo-50 text-indigo-500 dark:bg-indigo-900/30 dark:text-indigo-300'">
                        <PencilIcon class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{{ row.name }}</span>
                        <span v-if="row.note" class="block truncate text-xs text-gray-400 dark:text-slate-500">{{ row.note }}</span>
                    </span>
                    <span
                        class="flex-none rounded-md px-2.5 py-1 text-sm tabular-nums text-gray-700 ring-1 ring-gray-200 dark:text-slate-200 dark:ring-slate-600">
                        {{ formatQuantity(row.quantity) }}<template v-if="row.unit"> {{ row.unit }}</template>
                    </span>
                </button>

                <div v-if="openKey === row.key"
                    class="mx-3 mb-3 flex flex-col gap-3 rounded-lavoro-sm bg-gray-50 p-3 dark:bg-slate-800/60">
                    <p v-if="row.code" class="text-xs text-gray-500 dark:text-slate-400">Artikelnummer: {{ row.code }}</p>

                    <p v-if="isForeign(row.line)" class="text-xs text-gray-500 dark:text-slate-400">
                        {{ row.line.task_label ? 'Geboekt op de taak ' + row.line.task_label : 'Geboekt op een taak die jij niet kunt zien' }}.
                        Het aantal pas je aan bij die taak, in de standaardweergave.
                    </p>

                    <template v-if="!locked && canEditLine(row.line)">
                        <label v-if="row.freeform" class="flex flex-col gap-1">
                            <span class="text-xs font-semibold text-gray-500 dark:text-slate-400">Omschrijving</span>
                            <input v-model="draft.description" type="text"
                                class="rounded-md border-0 bg-white px-3 py-2 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-lavoro-blue focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-600"
                                @change="saveDescription(row)">
                        </label>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold text-gray-500 dark:text-slate-400">Aantal</span>
                            <div class="flex items-center gap-1">
                                <button type="button" aria-label="Eén minder" :disabled="Number(draft.quantity) <= 1"
                                    class="flex size-9 items-center justify-center rounded-md bg-white ring-1 ring-gray-300 disabled:opacity-40 dark:bg-slate-900 dark:ring-slate-600"
                                    @click="step(row, -1)">
                                    <MinusIcon class="size-4" />
                                </button>
                                <input v-model="draft.quantity" type="number" inputmode="decimal" min="0"
                                    class="h-9 w-20 rounded-md border-0 bg-white text-center text-sm tabular-nums text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-lavoro-blue focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-600"
                                    @change="saveQuantity(row, draft.quantity)">
                                <button type="button" aria-label="Eén meer"
                                    class="flex size-9 items-center justify-center rounded-md bg-white ring-1 ring-gray-300 dark:bg-slate-900 dark:ring-slate-600"
                                    @click="step(row, 1)">
                                    <PlusIcon class="size-4" />
                                </button>
                            </div>
                        </div>
                        <p v-if="quantityError(row.line)" class="-mt-2 text-xs text-red-600">
                            {{ quantityError(row.line) }}
                        </p>

                        <div v-if="showUnforseen" class="flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold text-gray-500 dark:text-slate-400">Onvoorzien</span>
                            <SwitchComponent :model-value="row.unforseen"
                                @update:model-value="updateLine(row.line, { unforseen: $event })" />
                        </div>
                    </template>

                    <button v-if="!locked && canRemoveLine(row.line)" type="button" @click="remove(row)"
                        class="inline-flex items-center justify-center gap-1.5 self-start rounded-md px-2 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                        <TrashIcon class="size-4" />
                        Verwijderen
                    </button>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500">Nog geen materialen gebruikt.</p>
    </BoxComponent>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Minus as MinusIcon, Package, Pencil as PencilIcon, Plus as PlusIcon, Trash2 as TrashIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import MaterialPicker from '@/Components/Materials/MaterialPicker.vue'
import FreeformMaterialForm from '@/Components/Materials/FreeformMaterialForm.vue'
import { useMaterialLines } from '@/Composables/useMaterialLines.js'

/**
 * De materialen voor wie op locatie werkt: naam en aantal, verder niets. Wie op
 * een regel tikt klapt eronder open wat er aan te passen valt; nog eens tikken
 * klapt hem weer dicht. Prijzen staan hier nooit, ook niet voor wie ze mag zien.
 *
 * Toevoegen klapt op dezelfde manier open, bovenaan de lijst, en blijft open na
 * elke regel: wie aan het eind van de dag vijf materialen boekt, hoeft het niet
 * vijf keer opnieuw te openen.
 */
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    materials: { type: Array, default: () => [] },
    freeformMaterials: { type: Array, default: () => [] },
    allMaterials: { type: Array, default: () => [] },
    materialsUseAjax: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    usageUnits: { type: Array, default: () => [] },
    isClosed: { type: Boolean, default: false },
    sentToAdministration: { type: Boolean, default: false },
    type: { type: String, default: null },
})

const {
    canSee,
    base,
    canCreate,
    canCreateFreeform,
    isForeign,
    canEditLine,
    canRemoveLine,
    quantityError,
    updateLine,
    removeLine,
} = useMaterialLines({ serviceOrderId: () => props.serviceOrderId })

const showUnforseen = computed(() => props.type === 'installation' || props.type === 'mixed')
const locked = computed(() => props.isClosed || props.sentToAdministration)
const canAdd = computed(() => !locked.value && (canCreate.value || canCreateFreeform.value))
const adding = ref(false)

const rows = computed(() => [
    ...props.materials.map(material => ({
        key: 'material-' + material.pivot.id,
        freeform: false,
        line: material,
        name: material.name,
        note: material.task_label ? 'Taak: ' + material.task_label : '',
        code: material.code,
        quantity: material.pivot.quantity,
        unit: material.usage_unit?.name ?? '',
        unforseen: !!material.pivot.unforseen,
    })),
    ...props.freeformMaterials.map(freeform => ({
        key: 'freeform-' + freeform.id,
        freeform: true,
        line: freeform,
        name: freeform.description,
        note: ['Vrije regel', freeform.task_label && 'Taak: ' + freeform.task_label].filter(Boolean).join(' · '),
        code: null,
        quantity: freeform.quantity,
        unit: '',
        unforseen: !!freeform.unforseen,
    })),
])

/** 10.00 als 10 en 2.50 als 2,5: een aantal, geen databasekolom. */
function formatQuantity(quantity) {
    return Number(quantity).toLocaleString('nl-NL', { maximumFractionDigits: 3 })
}

const openKey = ref(null)
const draft = reactive({ quantity: null, description: '' })

function toggle(row) {
    if (openKey.value === row.key) {
        openKey.value = null
        return
    }

    openKey.value = row.key
    draft.quantity = Number(row.quantity)
    draft.description = row.name
}

/**
 * De knoppen tellen mee onder je duim en slaan op zodra je stopt met tikken:
 * vijf keer plus is één wijziging van 1 naar 6, niet vijf opslagen die elkaar
 * naar de server inhalen. Het aantal wordt bij de tik vastgelegd, zodat een
 * andere regel die je intussen openklapt er niet zijn eigen aantal in legt.
 */
let pending_step = null

function saveQuantity(row, quantity) {
    clearTimeout(pending_step)

    if (Number(quantity) !== Number(row.quantity)) updateLine(row.line, { quantity: Number(quantity) })
}

function step(row, by) {
    const quantity = Math.max(1, Number(draft.quantity || 0) + by)

    draft.quantity = quantity
    clearTimeout(pending_step)
    pending_step = setTimeout(() => saveQuantity(row, quantity), 500)
}

function saveDescription(row) {
    if (draft.description !== row.name) updateLine(row.line, { description: draft.description })
}

function remove(row) {
    openKey.value = null
    removeLine(row.line)
}
</script>
