<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Producten" subtitle="Hieronder een lijst van alle producten" v-model="searchTerm"
            search-label="Zoek binnen producten" search-placeholder="bijv. 'Model X'" :in-action="inAction"
            :paginator="products" :pagination-params="{ search: searchTerm }" add-label="Voeg product toe"
            @add="() => productFormRef?.show()" />
    </div>

    <!-- Form box -->
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="productFormRef" external-trigger action="/products" :fields="productFields"
            add-button-label="Voeg product toe" submit-label="Opslaan" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalProducts.length" class="-mx-4 mt-3 sm:-mx-0 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-900">Model</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-900">Merk</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-900">Producttype</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-900">Verkoopperiode</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="product in internalProducts" :key="product.id">
                        <td class="px-4 py-2">
                            <div v-if="product.open">
                                <TextInput v-model="product.model" />
                            </div>
                            <Link :href="`/products/${product.id}`" class="text-blue-500 underline" v-else>{{
                                product.model }}</Link>
                        </td>
                        <td class="px-4 py-2">
                            <div v-if="product.open">
                                <ComboBox :options="brands" v-model="product.brand_id" :initialId="product.brand?.id"
                                    placeholder="Selecteer merk" class="pt-2" />
                            </div>
                            <span v-else>{{ product.brand?.name }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <div v-if="product.open">
                                <ComboBox :options="productTypes" v-model="product.product_type_id"
                                    :initialId="product.product_type?.id" placeholder="Selecteer producttype"
                                    class="pt-2" />
                            </div>
                            <span v-else>{{ product.product_type?.name }}</span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            <div v-if="product.open" class="grid grid-cols-2 gap-2 pt-2">
                                <input type="date" v-model="product.start_sell"
                                    class="ring ring-gray-300 rounded-md p-1 text-sm py-2" />
                                <input type="date" v-model="product.end_sell"
                                    class="ring ring-gray-300 rounded-md p-1 text-sm py-2" />
                            </div>
                            <span v-else>{{ new Date(product.start_sell).toLocaleDateString('nl-NL', {
                                day: '2-digit',
                                month: '2-digit',
                                year: 'numeric'
                            }) }} – {{
                                    new Date(product.end_sell).toLocaleDateString('nl-NL', {
                                        day: '2-digit', month:
                                            '2-digit',
                                        year: 'numeric'
                                    }) }}</span>
                        </td>
                        <td class="px-4 py-2 text-right text-sm font-medium">
                            <button v-if="!product.open" @click="toggleRecord(product.id)">
                                <PencilSquareIcon class="inline h-5 w-5 text-gray-600 mr-2 cursor-pointer" />
                            </button>
                            <button v-else @click="saveRecord(product)"
                                class="text-green-600 hover:text-green-900 mr-2">
                                Opslaan
                            </button>
                            <TrashIcon class="inline h-5 w-5 text-red-400 hover:text-red-600 cursor-pointer"
                                @click.stop="deleteProduct(product.id)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationComponent v-if="internalProducts.length" :paginator="products" :params="{ search: searchTerm }"
            class="border-t border-gray-200 pt-2" />
        <p v-else class="text-center text-gray-500 p-4">Geen producten gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref, watch, onMounted } from 'vue'
import debounce from 'lodash/debounce'
import TextInput from '@/Components/UI/TextInput.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'

const { products, search: initialSearch, brands, productTypes } = defineProps({
    products: { type: Object, required: true },
    search: { type: String, default: '' },
    brands: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] }
})

const searchTerm = ref(initialSearch)
const inAction = ref(false)
const productFormRef = ref(null)
const internalProducts = ref(products.data)

const productFields = [
    { key: 'product_type_id', label: 'Producttype', type: 'combobox', options: productTypes, initialId: productTypes[0]?.id },
    { key: 'brand_id', label: 'Merk', type: 'combobox', options: brands, initialId: brands[0]?.id },
    { key: 'model', label: 'Model', type: 'text' },
    { key: 'description', label: 'Beschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
    { key: 'start_sell', label: 'Start verkoop', type: 'date' },
    { key: 'end_sell', label: 'Einde verkoop', type: 'date' },
]

// Creation handled by backend redirect; no client-side mutations needed.

const deleteProduct = (id) => {
    if (!confirm('Weet je zeker dat je dit product wilt verwijderen?')) return
    useForm({}).delete(`/products/${id}`, { preserveScroll: true })
}

const toggleRecord = (id) => {
    internalProducts.value = internalProducts.value.map(product => {
        if (product.open) {
            const updateForm = useForm({ ...product })
            updateForm.patch(`/products/${product.id}`, { preserveScroll: true })
        }
        product.open = product.id === id ? !product.open : false
        return product
    })
    // ordering handled by backend
}


const saveRecord = (product) => {
    const form = useForm({ ...product })

    form.patch(`/products/${product.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            const updated = usePage().props.flash.extra
            internalProducts.value = internalProducts.value.map(p =>
                p.id === updated.id
                    ? { ...updated, open: false }
                    : p
            )
        }
    })
}

// Debounced search
const searchProducts = debounce((term) => {
    inAction.value = true
    localStorage.setItem('searchInitiated', 'true')
    router.get(`/products?search=${term}`, {}, { preserveScroll: true })
}, 300)

watch(searchTerm, newTerm => {
    searchProducts(newTerm)
})

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false
        localStorage.removeItem('searchInitiated')
        document.getElementById('searchInput')?.focus()
    }
})

</script>