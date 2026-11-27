<template>
    <div class="mx-3 mb-3 @4xl:ml-[5.5rem] rounded-lg bg-slate-50 dark:bg-slate-800/50 ring-1 ring-slate-200/70 dark:ring-slate-700">
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-sm font-semibold text-gray-700 dark:text-slate-200"
            @click="open = !open">
            <PackageIcon class="size-4 text-gray-500" />
            Materialen ({{ rows.length }})
            <ChevronDownIcon class="ml-auto size-4 text-gray-400 transition-transform" :class="open ? 'rotate-180' : ''" />
        </button>

        <div class="grid transition-[grid-template-rows] duration-300 ease-in-out" :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            @transitionend.self="settled = open">
            <div class="min-h-0" :class="open && settled ? 'overflow-visible' : 'overflow-hidden'" :inert="!open">
                <div class="ml-5 mb-3 border-l-2 border-slate-200 dark:border-slate-700 pl-2 transition-opacity duration-300"
                    :class="open ? 'opacity-100' : 'opacity-0'">
                    <draggable v-model="rows" handle=".draghandle" :animation="150" :disabled="!editable" @end="reorder">
                        <div v-for="child in rows" :key="child.id + '-' + child.updated_at"
                            class="group flex flex-wrap @4xl:flex-nowrap items-center gap-x-2 gap-y-1 py-1 text-sm border-b last:border-b-0 border-slate-200/70 dark:border-slate-700/60">
                            <QuoteMaterialRow :quote-id="quoteId" :line="child" :units="units" :editable="editable" />
                        </div>
                    </draggable>
                    <p v-if="!rows.length" class="py-2 text-xs text-gray-500 dark:text-slate-400">Nog geen materialen bij dit product.</p>

                    <div v-if="editable" class="pt-2">
                        <button v-if="!adding" type="button" @click="adding = true"
                            class="inline-flex items-center gap-1.5 rounded-md border border-blue-300 dark:border-blue-800 bg-white dark:bg-slate-900 px-3 py-1.5 text-sm font-medium text-lavoro-blue hover:bg-blue-50 dark:hover:bg-slate-800">
                            <PlusIcon class="size-4" /> Materiaal toevoegen
                        </button>
                        <div v-else class="flex flex-wrap items-start gap-2">
                            <ComboBox v-model="choice" :options="options" has-external-searching disable-internal-filter class="flex-1 min-w-48"
                                :searching="searching" placeholder="Zoek een materiaal, of typ wat het is" empty-message="Typ om te zoeken"
                                create-label="Anders, namelijk" tall @change="search" @create="addOwn"
                                :has-error="Boolean(error)" :error-message="error">
                                <template #option="{ option, active }">
                                    <CatalogOption :item="option.item" :active="active" />
                                </template>
                            </ComboBox>
                            <div :class="COLUMNS.quantity">
                                <input v-model="form.quantity" type="number" step="any" min="0" :class="BOXED_FIELD" aria-label="Aantal" />
                            </div>
                            <button type="button" :disabled="!choice || form.processing" @click="add"
                                class="rounded-md bg-lavoro-blue px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                                Toevoegen
                            </button>
                            <button type="button" @click="adding = false"
                                class="rounded-md px-3 py-1.5 text-sm text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800">
                                Klaar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import { ChevronDownIcon, PackageIcon, PlusIcon } from '@lucide/vue'
import CatalogOption from '@/Components/Quotes/CatalogOption.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import QuoteMaterialRow from '@/Components/Quotes/QuoteMaterialRow.vue'
import { BOXED_FIELD, COLUMNS } from '@/Components/Quotes/quoteColumns'
import { useQuoteCatalog } from '@/Components/Quotes/useQuoteCatalog'

/**
 * The materials under one product: open or closed, in an order that is dragged,
 * and with a field to add another from the catalogue, or, typed in with
 * "Anders, namelijk", one that is not in it. Whether it is open is the
 * page's to say, so "alles uitvouwen" reaches every product at once.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
    product: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
})

const open = defineModel('open', { type: Boolean, default: true })

/**
 * Folding is a height animation on a grid row, which clips what sticks out
 * while it moves. Once open and still, nothing is clipped, or the list of the
 * material search would be cut off at the panel's edge.
 */
const settled = ref(open.value)
watch(open, () => { settled.value = false })

const rows = ref([...props.product.children])
watch(() => props.product.children, (children) => { rows.value = [...children] })

const adding = ref(false)
const choice = ref(null)
const { options, searching, search, find } = useQuoteCatalog(props.quoteId, 'materials')
const form = useForm({ kind: 'material', material_id: null, quantity: 1, parent_id: props.product.id })
const own = useForm({ kind: 'free', description: '', quantity: 1, unit: 'stuk', parent_id: props.product.id })
const error = computed(() => form.errors.material_id || form.errors.parent_id || own.errors.description || own.errors.parent_id || null)

/** Something not in the catalogue, typed in: a line of its own under the product, at the quantity next to it. */
function addOwn(description) {
    own.description = description
    own.quantity = form.quantity
    own.post(`/quotes/${props.quoteId}/lines`, {
        preserveScroll: true,
        onSuccess: () => { choice.value = null; form.quantity = 1 },
    })
}

function add() {
    form.material_id = find(choice.value)?.id ?? null
    form.post(`/quotes/${props.quoteId}/lines`, {
        preserveScroll: true,
        onSuccess: () => { choice.value = null; form.quantity = 1 },
    })
}

function reorder() {
    router.put(`/quotes/${props.quoteId}/lines/order`, { line_ids: rows.value.map(row => row.id) }, { preserveScroll: true })
}
</script>
