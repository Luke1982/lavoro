<template>
    <IndexHeaderComponent title="Producten" subtitle="Hieronder een lijst van alle producten" search-url="/products"
        search-label="Zoek binnen producten" search-placeholder="Zoek op model, merk, omschrijving of artikelnummer"
        :search-other-params="filterParams" :paginator="products" add-label="Voeg product toe"
        @add="() => productFormRef?.show()">
        <template #filters>
            <div class="flex">
                <div class="flex-grow">
                    <div class="flex items-end gap-2">
                        <ComboBox :options="productTypes" v-model="productTypeToShow" multiple
                            placeholder="Selecteer producttype" class="w-full" label="Filter op type" />
                        <button type="button" @click="productTypeToShow = []"
                            class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600"
                            v-tooltip="'Reset filter op producttype'">
                            <XCircleIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>
                <div class="flex-grow">
                    <div class="flex items-end gap-2">
                        <ComboBox :options="brands" v-model="brandToShow" multiple placeholder="Selecteer merk"
                            class="w-full" label="Filter op merk" />
                        <button type="button" @click="brandToShow = []"
                            class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600"
                            v-tooltip="'Reset filter op merk'">
                            <XCircleIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>
                <div class="w-1/6 flex items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
            <div v-if="activeFilters.length" class="flex flex-wrap gap-2 mt-3" v-auto-animate>
                <span v-for="filter in activeFilters" :key="filter.key"
                    class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 bg-white dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <span class="text-gray-400 dark:text-slate-400">{{ filter.label }}:</span>
                    {{ filter.value }}
                    <button type="button" @click="filter.clear()"
                        class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                        <span class="sr-only">Verwijder filter</span>
                        <svg viewBox="0 0 14 14"
                            class="h-3.5 w-3.5 stroke-gray-600/75 group-hover:stroke-gray-600 dark:stroke-slate-400 dark:group-hover:stroke-slate-300">
                            <path d="M4 4l6 6m0-6l-6 6" />
                        </svg>
                        <span class="absolute -inset-1" />
                    </button>
                </span>
            </div>
        </template>
    </IndexHeaderComponent>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="productFormRef" external-trigger action="/products" :fields="productFields"
            add-button-label="Voeg product toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProducts.length"
            class="border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
            <div class="grid grid-cols-12 font-bold text-sm">
                <div class="col-span-4">Model</div>
                <div class="col-span-2">Merk</div>
                <div class="col-span-2">Producttype</div>
                <div class="col-span-2">Verkoopperiode</div>
                <div class="col-span-1">Bundel</div>
                <div class="col-span-1">Acties</div>
            </div>
        </div>
    </BoxComponent>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProducts.length" class="mt-3 sm:-mx-0 bg-white dark:bg-slate-900 p-px transition-colors"
            role="table">
            <!-- <div class="hidden lg:flex" role="row">
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:w-[28%]">
                    Model</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:w-[22%]">
                    Merk</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:w-[24%]">
                    Producttype</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:flex-grow">
                    Verkoopperiode</div>
                <div class="px-4 py-2 bg-gray-600 dark:bg-slate-700 shrink-0 flex items-center justify-end gap-3">
                    <span class="text-white text-sm font-semibold opacity-0">Acties</span>
                </div>
            </div> -->



            <div class="bg-white dark:bg-slate-900" role="rowgroup" v-auto-animate>
                <div v-for="product in displayProducts" :key="product.id" role="row"
                    class="even:bg-gray-50 dark:even:bg-slate-800/70 dark:bg-slate-900 grid grid-cols-12 py-3 lg:flex lg:flex-row lg:items-stretch border-b border-gray-100 dark:border-slate-800/60 last:border-b-0">
                    <!-- Model -->
                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 text-gray-800 dark:text-slate-200 lg:w-[28%]">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Model</span>
                        <div v-if="product.open">
                            <TextInput v-model="product.model" />
                        </div>
                        <Link v-else :href="`/products/${product.id}`"
                            class="text-blue-500 dark:text-blue-300 underline">
                            {{ product.model }}
                        </Link>
                    </div>

                    <!-- Merk -->
                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 text-gray-800 dark:text-slate-200 lg:w-[22%]">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Merk</span>
                        <div v-if="product.open">
                            <ComboBox :options="brands" v-model="product.brand_id" :initialId="product.brand?.id"
                                placeholder="Selecteer merk" class="pt-1" />
                        </div>
                        <span v-else>{{ product.brand?.name }}</span>
                    </div>

                    <!-- Producttype -->
                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 text-gray-800 dark:text-slate-200 lg:w-[24%]">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Producttype</span>
                        <div v-if="product.open">
                            <ComboBox :options="productTypes" v-model="product.product_type_id"
                                :initialId="product.product_type?.id" placeholder="Selecteer producttype"
                                class="pt-1" />
                        </div>
                        <span v-else>{{ product.product_type?.name }}</span>
                    </div>

                    <!-- Verkoopperiode -->
                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 text-gray-800 dark:text-slate-200 lg:flex-grow">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Verkoopperiode</span>
                        <div v-if="product.open" class="grid grid-cols-2 gap-2 pt-1">
                            <input type="date" v-model="product.start_sell"
                                class="ring ring-gray-300 rounded-md p-1 text-sm py-2 bg-white dark:bg-slate-800 dark:ring-slate-700/60 dark:text-slate-200" />
                            <input type="date" v-model="product.end_sell"
                                class="ring ring-gray-300 rounded-md p-1 text-sm py-2 bg-white dark:bg-slate-800 dark:ring-slate-700/60 dark:text-slate-200" />
                        </div>
                        <span v-else>{{ formatProductSalePeriod(product.start_sell, product.end_sell, 'index') }}</span>
                    </div>

                    <!-- Acties -->
                    <div class="px-4 py-2 text-right flex items-center justify-end gap-3 col-span-12 lg:shrink-0">
                        <button v-if="!product.open" @click="toggleRecord(product.id)"
                            class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
                            <PencilSquareIcon class="inline h-5 w-5 mr-2" />
                        </button>
                        <button v-else @click="saveRecord(product)"
                            class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">
                            Opslaan
                        </button>
                        <TrashIcon
                            class="inline h-5 w-5 text-red-400 dark:text-red-300 hover:text-red-600 dark:hover:text-red-400 cursor-pointer"
                            @click.stop="deleteProduct(product.id)" />
                    </div>
                </div>
            </div>
        </div>
        <PaginationComponent v-if="displayProducts.length" :paginator="products"
            class="border-t border-gray-200 pt-2 dark:border-slate-700/60" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen producten gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { XCircleIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { formatProductSalePeriod } from '@/Utilities/Utilities'
import { RotateCcwIcon } from '@lucide/vue'

const { products, brands, productTypes } = defineProps({
    products: { type: Object, required: true },
    brands: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] }
})

const productFormRef = ref(null)
const openIds = ref(new Set())
const displayProducts = computed(() => (products.data || []).map(p => ({
    ...p,
    open: openIds.value.has(p.id)
})))
// product type filter
const typeFromURL = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('onlyType') || '').split(',').map(Number).filter(Boolean)
    : []
const productTypeToShow = ref(typeFromURL)

// brand filter
const brandFromURL = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('onlyBrand') || '').split(',').map(Number).filter(Boolean)
    : []
const brandToShow = ref(brandFromURL)

const filterParams = computed(() => ({
    onlyType: productTypeToShow.value.join(','),
    onlyBrand: brandToShow.value.join(','),
}))

const activeFilters = computed(() => {
    const filters = []
    productTypeToShow.value.forEach(id => {
        const match = productTypes.find(t => t.id === id)
        if (match) filters.push({ key: `type-${id}`, label: 'Type', value: match.name, clear: () => { productTypeToShow.value = productTypeToShow.value.filter(x => x !== id) } })
    })
    brandToShow.value.forEach(id => {
        const match = brands.find(b => b.id === id)
        if (match) filters.push({ key: `brand-${id}`, label: 'Merk', value: match.name, clear: () => { brandToShow.value = brandToShow.value.filter(x => x !== id) } })
    })
    return filters
})

function clearAllFilters() {
    productTypeToShow.value = []
    brandToShow.value = []
}

const productFields = [
    { key: 'product_type_id', label: 'Producttype', type: 'combobox', options: productTypes, initialId: productTypes[0]?.id },
    { key: 'brand_id', label: 'Merk', type: 'combobox', options: brands, initialId: brands[0]?.id },
    { key: 'model', label: 'Model', type: 'text' },
    { key: 'description', label: 'Beschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
    { key: 'start_sell', label: 'Start verkoop', type: 'date', placeholder: 'Optioneel' },
    { key: 'end_sell', label: 'Einde verkoop', type: 'date', placeholder: 'Optioneel' },
    { key: 'retail_price', label: 'Verkoopprijs', type: 'currency', placeholder: 'Optioneel' },
    { key: 'purchase_price', label: 'Inkoopprijs', type: 'currency', placeholder: 'Optioneel' },
    { key: 'part_no', label: 'Artikelnummer', type: 'text', placeholder: 'Optioneel' },
]

const deleteProduct = (id) => {
    if (!confirm('Weet je zeker dat je dit product wilt verwijderen?')) return
    useForm({}).delete(`/products/${id}`, { preserveScroll: true })
}

const toggleRecord = (id) => {
    const currentlyOpen = displayProducts.value.find(p => p.open)
    if (currentlyOpen && currentlyOpen.id !== id) {
        const updateForm = useForm({ ...currentlyOpen })
        updateForm.patch(`/products/${currentlyOpen.id}`, { preserveScroll: true })
        openIds.value.delete(currentlyOpen.id)
    }
    if (openIds.value.has(id)) openIds.value.delete(id)
    else openIds.value.add(id)
}


const saveRecord = (product) => {
    const form = useForm({ ...product })

    form.patch(`/products/${product.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            openIds.value.delete(product.id)
        }
    })
}
</script>