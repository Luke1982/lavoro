<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent title="Producten" subtitle="Hieronder een lijst van alle producten" search-url="/products"
            search-label="Zoek binnen producten" search-placeholder="bijv. 'Model X'"
            :search-other-params="{ onlyType: productTypeToShow }" :paginator="products" add-label="Voeg product toe"
            @add="() => productFormRef?.show()">
            <template #right>
                <div class="w-full">
                    <label class="block text-sm font-medium mb-2">Filter op type</label>
                    <div class="flex items-center gap-2">
                        <ComboBox :options="productTypes" v-model="productTypeToShow"
                            placeholder="Selecteer producttype" class="w-full" />
                        <button type="button" @click="resetFilter"
                            class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600"
                            v-tooltip="'Reset filter op producttype'">
                            <XCircleIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>
            </template>
        </IndexHeaderComponent>
    </div>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="productFormRef" external-trigger action="/products" :fields="productFields"
            add-button-label="Voeg product toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProducts.length"
            class="mt-3 sm:-mx-0 rounded-md border border-gray-300 dark:border-slate-700/60 bg-white dark:bg-slate-900 p-px transition-colors"
            role="table">
            <div class="hidden lg:flex" role="row">
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 rounded-tl-md lg:w-[28%]">
                    Model</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:w-[22%]">
                    Merk</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:w-[24%]">
                    Producttype</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 lg:w-[26%]">
                    Verkoopperiode</div>
                <div
                    class="px-4 py-2 bg-gray-600 dark:bg-slate-700 rounded-tr-md flex-grow flex items-center justify-end gap-3">
                    <span class="text-white text-sm font-semibold opacity-0">Acties</span>
                </div>
            </div>

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
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 text-gray-800 dark:text-slate-200 lg:w-[26%]">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Verkoopperiode</span>
                        <div v-if="product.open" class="grid grid-cols-2 gap-2 pt-1">
                            <input type="date" v-model="product.start_sell"
                                class="ring ring-gray-300 rounded-md p-1 text-sm py-2 bg-white dark:bg-slate-800 dark:ring-slate-700/60 dark:text-slate-200" />
                            <input type="date" v-model="product.end_sell"
                                class="ring ring-gray-300 rounded-md p-1 text-sm py-2 bg-white dark:bg-slate-800 dark:ring-slate-700/60 dark:text-slate-200" />
                        </div>
                        <span v-else>
                            {{ new Date(product.start_sell).toLocaleDateString('nl-NL', {
                                day: '2-digit', month:
                                    '2-digit',
                                year: 'numeric'
                            }) }}
                            –
                            {{ new Date(product.end_sell).toLocaleDateString('nl-NL', {
                                day: '2-digit', month:
                                    '2-digit',
                                year: 'numeric'
                            }) }}
                        </span>
                    </div>

                    <!-- Acties -->
                    <div class="px-4 py-2 text-right flex items-center justify-end gap-3 col-span-12 lg:flex-grow">
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
    ? new URLSearchParams(window.location.search).get('onlyType')
    : null
const productTypeToShow = ref(typeFromURL ? Number(typeFromURL) : null)

const productFields = [
    { key: 'product_type_id', label: 'Producttype', type: 'combobox', options: productTypes, initialId: productTypes[0]?.id },
    { key: 'brand_id', label: 'Merk', type: 'combobox', options: brands, initialId: brands[0]?.id },
    { key: 'model', label: 'Model', type: 'text' },
    { key: 'description', label: 'Beschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
    { key: 'start_sell', label: 'Start verkoop', type: 'date' },
    { key: 'end_sell', label: 'Einde verkoop', type: 'date' },
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

function resetFilter() {
    productTypeToShow.value = null
}

</script>