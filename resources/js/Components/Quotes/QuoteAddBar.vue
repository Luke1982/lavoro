<template>
    <div class="flex flex-col gap-3 p-4 border-t border-gray-200 dark:border-slate-700 md:flex-row md:items-end">
        <div class="flex-grow">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Product of materiaal</span>
            <ComboBox v-model="choice" :options="options" has-external-searching disable-internal-filter
                :searching="searching" placeholder="Zoek op merk, model, naam of code" empty-message="Typ om te zoeken"
                @change="search" />
        </div>
        <div class="md:w-28">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Aantal</span>
            <TextInput v-model="quantity" type="number" />
        </div>
        <button type="button" @click="add" :disabled="!picked || busy"
            class="px-4 py-2 rounded-md text-sm font-medium bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50">
            Toevoegen
        </button>
        <div class="flex gap-2">
            <button v-for="(label, kind) in OWN_LINES" :key="kind" type="button" @click="addOwn(kind)" :disabled="busy"
                class="px-3 py-2 rounded-md text-sm border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-800">
                {{ label }}
            </button>
        </div>
    </div>
    <p v-if="error" class="px-4 pb-3 text-sm text-red-600 dark:text-red-400">{{ error }}</p>

    <CompanionMaterialsDialog :open="asking" :product="picked" :quantity="Number(quantity) || 1"
        :errors="productForm.errors" :processing="productForm.processing" @cancel="asking = false" @confirm="addProduct" />
</template>

<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'
import { debounce } from 'lodash'
import { useForm } from '@inertiajs/vue3'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CompanionMaterialsDialog from '@/Components/Quotes/CompanionMaterialsDialog.vue'
import { nlCurrency } from '@/Utilities/Utilities'

/**
 * Putting something on a quote. Products and materials are found in one field.
 * A product with materials to ask about opens the question first; one whose
 * materials all come along by themselves, or that has none, goes straight on.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
})

const OWN_LINES = { free: 'Eigen regel', text: 'Tekst' }

const results = ref([])
const searching = ref(false)
const choice = ref(null)
const quantity = ref(1)
const asking = ref(false)

const productForm = useForm({ product_id: null, quantity: 1, materials: [] })
const lineForm = useForm({ kind: null, material_id: null, quantity: 1, description: null })

const picked = computed(() => results.value.find(item => item.key === choice.value) ?? null)
const busy = computed(() => productForm.processing || lineForm.processing)
const error = computed(() => productForm.errors.product_id || productForm.errors.quantity
    || lineForm.errors.material_id || lineForm.errors.quantity || null)

const options = computed(() => results.value.map(item => ({
    id: item.key,
    name: `${item.type === 'product' ? 'Product' : 'Materiaal'}: ${item.name}, ${nlCurrency(item.price)}`
        + (item.defaults?.length ? ` (+${item.defaults.length} materialen)` : ''),
})))

const search = debounce(async (q) => {
    if (!q) return
    searching.value = true
    try {
        results.value = (await axios.get(`/quotes/${props.quoteId}/catalog`, { params: { q } })).data
    } finally {
        searching.value = false
    }
}, 200)

function add() {
    if (picked.value.type === 'material') {
        lineForm.kind = 'material'
        lineForm.material_id = picked.value.id
        lineForm.quantity = quantity.value
        lineForm.description = null
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
    productForm.product_id = picked.value.id
    productForm.quantity = quantity.value
    productForm.materials = materials
    productForm.post(`/quotes/${props.quoteId}/products`, {
        preserveScroll: true,
        onSuccess: () => { asking.value = false; reset() },
    })
}

function addOwn(kind) {
    lineForm.kind = kind
    lineForm.material_id = null
    lineForm.quantity = kind === 'text' ? 0 : 1
    lineForm.description = kind === 'text' ? 'Nieuwe tekst' : 'Nieuwe regel'
    lineForm.post(`/quotes/${props.quoteId}/lines`, { preserveScroll: true })
}

function reset() {
    choice.value = null
    quantity.value = 1
}
</script>
