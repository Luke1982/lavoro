<template>
    <div class="m-4 rounded-xl border-2 border-dashed border-blue-200 dark:border-blue-900/60 p-3">
        <div class="flex flex-wrap items-center gap-2">
            <DropdownMenu placement="bottom-start" width-class="w-60" title="Regel toevoegen"
                button-class="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-base font-semibold text-lavoro-blue hover:bg-blue-50 dark:hover:bg-slate-800">
                <template #button>
                    <PlusIcon class="size-5" /> Regel toevoegen <ChevronDownIcon class="size-4" />
                </template>
                <MenuItem v-for="kind in KINDS_TO_ADD" :key="kind.key" v-slot="{ active }">
                    <button type="button" :class="menuItem(active)" @click="start(kind.key)">
                        <component :is="kind.icon" class="size-4" /> {{ kind.label }}
                    </button>
                </MenuItem>
            </DropdownMenu>
            <div class="flex-1" />
            <button v-for="kind in KINDS_TO_ADD" :key="kind.key" type="button" @click="start(kind.key)" :disabled="busy"
                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                :class="mode === kind.key
                    ? 'border-lavoro-blue bg-blue-50 dark:bg-slate-800 text-lavoro-blue'
                    : 'border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-800'">
                <component :is="kind.icon" class="size-4" /> {{ kind.label }}
            </button>
        </div>

        <div v-if="mode === 'catalog' || mode === 'option'" class="mt-3 flex flex-col gap-2 md:flex-row md:items-start">
            <ComboBox v-model="choice" :options="options" has-external-searching disable-internal-filter class="flex-1"
                :searching="searching" placeholder="Zoek op merk, model, naam of code" empty-message="Typ om te zoeken"
                @change="search" />
            <div :class="COLUMNS.quantity">
                <TextInput v-model="quantity" type="number" aria-label="Aantal" />
            </div>
            <button type="button" @click="add" :disabled="!picked || busy"
                class="rounded-md bg-lavoro-blue px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50">
                {{ mode === 'option' ? 'Als optie toevoegen' : 'Toevoegen' }}
            </button>
        </div>
        <p v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <CompanionMaterialsDialog :open="asking" :product="picked" :quantity="Number(quantity) || 1"
        :errors="productForm.errors" :processing="productForm.processing" @cancel="asking = false" @confirm="addProduct" />
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import { AlignLeftIcon, ChevronDownIcon, PackageIcon, PlusIcon, StarIcon, WrenchIcon } from '@lucide/vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CompanionMaterialsDialog from '@/Components/Quotes/CompanionMaterialsDialog.vue'
import { COLUMNS, menuItem } from '@/Components/Quotes/quoteColumns'
import { useQuoteCatalog } from '@/Components/Quotes/useQuoteCatalog'

/**
 * Putting something on a quote. A product or material is searched for, and an
 * option is the same search with the line marked optional; a product with
 * materials to ask about opens the question first. A line of one's own and a
 * text go on at once, to be filled in where they land.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
})

const KINDS_TO_ADD = [
    { key: 'catalog', label: 'Product of materiaal', icon: PackageIcon },
    { key: 'free', label: 'Dienst / eigen regel', icon: WrenchIcon },
    { key: 'text', label: 'Tekstregel', icon: AlignLeftIcon },
    { key: 'option', label: 'Optionele regel', icon: StarIcon },
]

const mode = ref(null)
const choice = ref(null)
const quantity = ref(1)
const asking = ref(false)

const { options, searching, search, find } = useQuoteCatalog(props.quoteId)
const picked = computed(() => find(choice.value))

const productForm = useForm({ product_id: null, quantity: 1, materials: [], is_optional: false })
const lineForm = useForm({ kind: null, material_id: null, quantity: 1, unit: null, description: null, is_optional: false })

const busy = computed(() => productForm.processing || lineForm.processing)
const error = computed(() => productForm.errors.product_id || productForm.errors.quantity
    || lineForm.errors.material_id || lineForm.errors.quantity || null)
const optional = computed(() => mode.value === 'option')

function start(kind) {
    if (kind === 'free' || kind === 'text') {
        mode.value = null
        addOwn(kind)
        return
    }
    mode.value = mode.value === kind ? null : kind
}

function add() {
    if (picked.value.type === 'material') {
        Object.assign(lineForm, {
            kind: 'material', material_id: picked.value.id, quantity: quantity.value, unit: null, description: null, is_optional: optional.value,
        })
        lineForm.post(`/quotes/${props.quoteId}/lines`, { preserveScroll: true, onSuccess: reset })
        return
    }
    if (picked.value.defaults.some(row => row.prompt !== 'always')) {
        productForm.clearErrors()
        asking.value = true
        return
    }
    addProduct([])
}

function addProduct(materials) {
    Object.assign(productForm, { product_id: picked.value.id, quantity: quantity.value, materials, is_optional: optional.value })
    productForm.post(`/quotes/${props.quoteId}/products`, {
        preserveScroll: true,
        onSuccess: () => { asking.value = false; reset() },
    })
}

function addOwn(kind) {
    Object.assign(lineForm, {
        kind,
        material_id: null,
        quantity: kind === 'text' ? 0 : 1,
        unit: kind === 'text' ? null : 'uur',
        description: kind === 'text' ? 'Nieuwe kop' : 'Nieuwe regel',
        is_optional: false,
    })
    lineForm.post(`/quotes/${props.quoteId}/lines`, { preserveScroll: true })
}

function reset() {
    choice.value = null
    quantity.value = 1
}
</script>
