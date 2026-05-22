<template>
    <IndexHeaderComponent title="Producten" subtitle="Hieronder een lijst van alle producten" search-url="/products"
        search-label="Zoek binnen producten" search-placeholder="Zoek op model, merk, omschrijving of artikelnummer"
        :search-other-params="filterParams" :paginator="false" add-label="Voeg product toe"
        :has-active-filters="activeFilters.length > 0" @add="showProductDrawer = true"
        :can-add="hasPermission('product.create')">
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-y-4 sm:gap-y-0">
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
                <div class="hidden sm:flex w-1/6 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
            <div v-if="productAttributes.length" class="flex flex-wrap gap-4 mt-4">
                <div v-for="attr in productAttributes" :key="attr.id" class="flex-grow min-w-48">
                    <div class="flex items-end gap-2">
                        <ComboBox :options="attr.values.map(v => ({ id: v.id, name: v.value }))"
                            v-model="attrValuesSelected[attr.id]" multiple
                            :placeholder="`Selecteer ${attr.name.toLowerCase()}`" class="w-full"
                            :label="`Filter op ${attr.name.toLowerCase()}`" />
                        <button type="button" @click="attrValuesSelected[attr.id] = []"
                            class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600"
                            v-tooltip="`Reset filter op ${attr.name.toLowerCase()}`">
                            <XCircleIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>
            </div>
            <div v-if="activeFilters.length" class="flex flex-wrap gap-2 mt-3" v-auto-animate>
                <span v-for="filter in activeFilters" :key="filter.key"
                    class="inline-flex items-center gap-x-1.5 rounded-md px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 bg-white dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
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
                <div class="flex sm:hidden p-2 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    v-if="activeFilters.length" @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
        </template>
    </IndexHeaderComponent>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProducts.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-4">Model</div>
                <div class="col-span-2">Merk</div>
                <div class="col-span-2">Producttype</div>
                <div class="col-span-2">Verkoopperiode</div>
                <div class="col-span-1">Bundel</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="product in displayProducts" :key="product.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="col-span-10 sm:col-span-4 flex items-center gap-4">
                    <div
                        class="w-20 h-20 p-1 rounded-sm border-lavoro-lightgray border-1 flex items-center justify-center">
                        <img :src="product.main_image?.[0] ? `/storage/${product.main_image[0].path}` : '/img/placeholder.png'"
                            alt="">
                    </div>
                    <div class="flex flex-col">
                        <Link :href="`/products/${product.id}`" class="font-bold mb-1">
                            {{ product.brand.name }} {{ product.model }}
                        </Link>
                        <span class="text-slate-600">{{ product.part_no }}</span>
                        <div v-if="product.attribute_value_map" class="mt-1">
                            <div v-for="(value, key) in product.attribute_value_map" :key="key"
                                class="grid grid-cols-3 text-xs text-gray-500 mt-0.5">
                                <div class="col-span-1 pr-2 font-bold">{{ key }}</div>
                                <div class="col-span-2">{{ value }}</div>
                            </div>
                        </div>
                        <div class="flex flex-col sm:hidden text-xs text-gray-500 mt-1 gap-y-2 items-start">
                            {{ product.product_type?.name }}
                            <BadgeComponent :color="product.bundle ? 'green' : 'gray'">
                                {{ product.bundle ? 'Bundel' : 'Geen bundel' }}
                            </BadgeComponent>
                        </div>
                    </div>
                </div>
                <div class="col-span-2 items-center pr-2 hidden sm:flex">
                    <EditableTextField type="combobox" :model-value="product.brand_id" :options="brands"
                        :decoration="false"
                        @update="(val) => router.patch(`/products/${product.id}`, { brand_id: val }, { preserveScroll: true })">
                        <template #display>{{ product.brand?.name }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 items-center hidden sm:flex pr-2">
                    <EditableTextField type="combobox" :model-value="product.product_type_id" :options="productTypes"
                        :decoration="false"
                        @update="(val) => router.patch(`/products/${product.id}`, { product_type_id: val }, { preserveScroll: true })">
                        <template #display>{{ product.product_type?.name }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 items-center hidden sm:flex pr-2">
                    <EditableTextField :decoration="false"
                        @open="saleEdits[product.id] = { start_sell: product.start_sell ?? '', end_sell: product.end_sell ?? '' }">
                        <template #display>{{ formatProductSalePeriod(product.start_sell,
                            product.end_sell, 'index') }}</template>
                        <template #open="{ close }">
                            <div class="flex flex-col gap-2 w-full" @click.stop>
                                <input type="date" v-model="saleEdits[product.id].start_sell"
                                    class="ring ring-gray-300 rounded-md p-1 text-sm py-2 bg-white dark:bg-slate-800 dark:ring-slate-700/60 dark:text-slate-200" />
                                <input type="date" v-model="saleEdits[product.id].end_sell"
                                    class="ring ring-gray-300 rounded-md p-1 text-sm py-2 bg-white dark:bg-slate-800 dark:ring-slate-700/60 dark:text-slate-200" />
                                <button @click.stop="saveSalePeriod(product.id, close)"
                                    class="text-sm text-green-600 hover:text-green-800 dark:text-green-400 font-medium text-left">Opslaan</button>
                            </div>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 items-center hidden sm:flex pr-2">
                    <EditableTextField :decoration="false" @open="bundleEdits[product.id] = { bundle: product.bundle }">
                        <template #display>
                            <BadgeComponent :color="product.bundle ? 'green' : 'gray'">
                                {{ product.bundle ? 'Bundel' : 'Geen bundel' }}
                            </BadgeComponent>
                        </template>
                        <template #open="{ close }">
                            <div class="flex flex-col gap-2" @click.stop>
                                <SwitchComponent v-model="bundleEdits[product.id].bundle"
                                    @update:modelValue="updateProduct(product.id, { bundle: bundleEdits[product.id].bundle }, close)" />
                            </div>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 sm:col-span-1 items-center flex justify-end">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex flex-col sm:flex-row">
                        <div class="pb-2 sm:pb-0">
                            <Link :href="`/products/${product.id}`" class="text-sm text-lavoro-darkerblue">
                                <EyeIcon class="h-5 w-5" />
                            </Link>
                        </div>
                        <div v-if="hasPermission('product.delete')"
                            class="ml-0 sm:ml-2 border-l-lavoro-darkblue border-l-0 sm:border-l-1 border-t-1 sm:border-t-0 pl-0 sm:pl-2 pt-2 sm:pt-0">
                            <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteProduct(product.id)" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-between bg-white rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="products.total" :per-page="perPage" label="producten" />
                <PaginationComponent v-if="products.data.length" :paginator="products" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <BoxIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen producten gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="showProductDrawer" title="Nieuw product toevoegen"
        subtitle="Vul onderstaande velden in om een nieuw product toe te voegen." max-width-class="max-w-2xl">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Producttype</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="productTypes" v-model="newProductForm.product_type_id"
                        placeholder="Selecteer producttype" :hasError="Boolean(newProductForm.errors.product_type_id)"
                        :errorMessage="newProductForm.errors.product_type_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Merk</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="brands" v-model="newProductForm.brand_id" placeholder="Selecteer merk"
                        :hasError="Boolean(newProductForm.errors.brand_id)"
                        :errorMessage="newProductForm.errors.brand_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Model</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newProductForm.model" type="text"
                        :hasError="Boolean(newProductForm.errors.model)" :errorMessage="newProductForm.errors.model" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-start">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200 sm:pt-2">Beschrijving</label>
                <div class="sm:col-span-2">
                    <textarea v-model="newProductForm.description" rows="3"
                        class="block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm p-2"
                        placeholder="Optioneel"></textarea>
                    <p v-if="newProductForm.errors.description" class="text-red-600 text-sm mt-1">{{
                        newProductForm.errors.description }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Artikelnummer</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newProductForm.part_no" type="text" placeholder="Optioneel"
                        :hasError="Boolean(newProductForm.errors.part_no)"
                        :errorMessage="newProductForm.errors.part_no" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Start verkoop</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newProductForm.start_sell" type="date"
                        :hasError="Boolean(newProductForm.errors.start_sell)"
                        :errorMessage="newProductForm.errors.start_sell" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Einde verkoop</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newProductForm.end_sell" type="date"
                        :hasError="Boolean(newProductForm.errors.end_sell)"
                        :errorMessage="newProductForm.errors.end_sell" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Verkoopprijs</label>
                <div class="sm:col-span-2">
                    <CurrencyInput v-model="newProductForm.retail_price" placeholder="Optioneel"
                        :hasError="Boolean(newProductForm.errors.retail_price)"
                        :errorMessage="newProductForm.errors.retail_price" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Inkoopprijs</label>
                <div class="sm:col-span-2">
                    <CurrencyInput v-model="newProductForm.purchase_price" placeholder="Optioneel"
                        :hasError="Boolean(newProductForm.errors.purchase_price)"
                        :errorMessage="newProductForm.errors.purchase_price" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200 flex">
                    Bundel
                    <InfoIcon class="h-4 w-4 text-gray-500 mt-0.5 ml-1"
                        v-tooltip="'Een gebundeld product wordt gebruikt om andere producten te groeperen. Machines die van bundels gemaakt worden krijgen geen serienummer.'" />
                </label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="newProductForm.bundle" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeProductDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewProduct" :disabled="newProductForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { Link, useForm, router } from '@inertiajs/vue3'
import { ref, computed, reactive } from 'vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { XCircleIcon } from '@heroicons/vue/24/outline'
import { formatProductSalePeriod, hasPermission } from '@/Utilities/Utilities'
import { EyeIcon, InfoIcon, RotateCcwIcon, TrashIcon } from '@lucide/vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'

const { products, brands, productTypes, productAttributes, perPage } = defineProps({
    products: { type: Object, required: true },
    brands: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] },
    productAttributes: { type: Array, default: () => [] },
    perPage: { type: Number, default: 20 },
})

const showProductDrawer = ref(false)

const newProductForm = useForm({
    product_type_id: null,
    brand_id: null,
    model: '',
    description: '',
    part_no: '',
    start_sell: null,
    end_sell: null,
    retail_price: null,
    purchase_price: null,
    bundle: false,
})

function submitNewProduct() {
    newProductForm.post('/products', {
        preserveScroll: true,
        onSuccess: () => {
            showProductDrawer.value = false
            newProductForm.reset()
        },
    })
}

function closeProductDrawer() {
    showProductDrawer.value = false
    newProductForm.reset()
    newProductForm.clearErrors()
}

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

// attribute values filter — one selection array per attribute
const attrValFromURL = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('onlyAttributeValues') || '').split(',').map(Number).filter(Boolean)
    : []
const attrValuesSelected = reactive(
    productAttributes.reduce((acc, attr) => {
        const attrValueIds = new Set(attr.values.map(v => v.id))
        acc[attr.id] = attrValFromURL.filter(id => attrValueIds.has(id))
        return acc
    }, {})
)

const filterParams = computed(() => ({
    onlyType: productTypeToShow.value.join(','),
    onlyBrand: brandToShow.value.join(','),
    onlyAttributeValues: Object.values(attrValuesSelected).flat().join(','),
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
    productAttributes.forEach(attr => {
        ; (attrValuesSelected[attr.id] || []).forEach(id => {
            const val = attr.values.find(v => v.id === id)
            if (val) filters.push({ key: `attrval-${id}`, label: attr.name, value: val.value, clear: () => { attrValuesSelected[attr.id] = attrValuesSelected[attr.id].filter(x => x !== id) } })
        })
    })
    return filters
})

function clearAllFilters() {
    productTypeToShow.value = []
    brandToShow.value = []
    productAttributes.forEach(attr => { attrValuesSelected[attr.id] = [] })
}

const saleEdits = reactive({})
const bundleEdits = reactive({})

function updateProduct(product_id, data, close = null) {
    router.patch(`/products/${product_id}`, data, {
        preserveScroll: true,
        onSuccess: () => close?.()
    })
}

function saveSalePeriod(productId, close) {
    const edit = saleEdits[productId]
    updateProduct(productId, { start_sell: edit.start_sell, end_sell: edit.end_sell }, close)
}

const deleteProduct = (id) => {
    if (!confirm('Weet je zeker dat je dit product wilt verwijderen?')) return
    useForm({}).delete(`/products/${id}`, { preserveScroll: true, preserveState: true })
}

</script>