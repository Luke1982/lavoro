<template>
    <div class="p-4 bg-white rounded-md">
        <div class="sm:flex justify-between items-center flex-wrap mb-4">
            <div>
                <h1 class="text-base font-semibold">Producten</h1>
                <p class="text-sm text-gray-700">Hieronder een lijst van alle producten</p>
            </div>
            <button v-if="addingProduct === false" @click="addingProduct = true"
                class="inline-flex items-center px-3 py-2 border border-green-900 text-green-900 bg-green-100 rounded-md text-sm">
                <PlusCircleIcon class="h-5 w-5 mr-1" />
                Voeg product toe
            </button>
        </div>

        <div class="mb-4" v-if="!addingProduct">
            <SearchComponent v-model="searchTerm" url="/products" label="Zoek binnen producten"
                placeholder="bijv. 'Model X'" input-id="searchInput" />
        </div>

        <div v-if="addingProduct" class="mb-6 p-4 ring ring-gray-300 rounded-md relative">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium">Producttype</label>
                    <div class="mt-1">
                        <ComboBox :options="productTypes" v-model="newProductForm.product_type_id"
                            placeholder="Selecteer producttype" />
                    </div>
                    <p v-if="newProductForm.errors.product_type_id" class="text-red-600 text-sm">
                        {{ newProductForm.errors.product_type_id }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium">Merk</label>
                    <div class="mt-1">
                        <ComboBox :options="brands" v-model="newProductForm.brand_id" placeholder="Selecteer merk" />
                    </div>
                    <p v-if="newProductForm.errors.brand_id" class="text-red-600 text-sm">
                        {{ newProductForm.errors.brand_id }}
                    </p>
                </div>

                <TextInput v-model="newProductForm.model" label="Model" :hasError="newProductForm.errors.model"
                    :errorMessage="newProductForm.errors.model" />

                <div>
                    <label class="block text-sm font-medium">Beschrijving</label>
                    <textarea v-model="newProductForm.description" rows="3"
                        class="mt-1 block w-full rounded-md focus:ring-indigo-600 focus:border-indigo-600 sm:text-sm ring ring-gray-300 p-2"
                        placeholder="Optioneel"></textarea>
                    <p v-if="newProductForm.errors.description" class="text-red-600 text-sm">
                        {{ newProductForm.errors.description }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium">Start verkoop</label>
                        <input type="date" v-model="newProductForm.start_sell"
                            class="mt-1 block w-full ring ring-gray-300 p-2 rounded-md focus:ring-indigo-600 focus:border-indigo-600 sm:text-sm" />
                        <p v-if="newProductForm.errors.start_sell" class="text-red-600 text-sm">
                            {{ newProductForm.errors.start_sell }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Einde verkoop</label>
                        <input type="date" v-model="newProductForm.end_sell"
                            class="mt-1 block w-full ring ring-gray-300 p-2 rounded-md focus:ring-indigo-600 focus:border-indigo-600 sm:text-sm" />
                        <p v-if="newProductForm.errors.end_sell" class="text-red-600 text-sm">
                            {{ newProductForm.errors.end_sell }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="absolute top-2 right-2 flex space-x-2">
                <button @click="newProductForm.post('/products', { preserveScroll: true, onSuccess: onCreateSuccess })"
                    class="px-3 py-1 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">
                    Opslaan
                </button>
                <XCircleIcon class="h-6 w-6 text-gray-400 cursor-pointer"
                    @click="newProductForm.reset(); addingProduct = false" />
            </div>
        </div>

        <nav v-if="internalProducts.length"
            class="flex items-center justify-between border-b border-gray-200 px-4 pb-2 sm:px-0">
            <div class="flex-1">
                <Link v-if="products.prev_page_url"
                    :href="`${products.prev_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                &laquo; Vorige
                </Link>
            </div>
            <div class="hidden md:flex space-x-1">
                <Link v-for="link in links" :key="link.url"
                    :href="`${link.url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="px-3 py-1 text-sm font-medium border rounded hover:border-gray-300 hover:text-gray-700"
                    :class="link.active
                        ? 'border-indigo-500 text-indigo-600'
                        : 'border-transparent text-gray-500'" preserve-scroll>
                {{ link.label }}
                </Link>
            </div>
            <div class="flex-1 text-right">
                <Link v-if="products.next_page_url"
                    :href="`${products.next_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                Volgende &raquo;
                </Link>
            </div>
        </nav>

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

        <nav v-if="internalProducts.length"
            class="flex items-center justify-between border-t border-gray-200 px-4 pt-2 sm:px-0">
            <div class="flex-1">
                <Link v-if="products.prev_page_url"
                    :href="`${products.prev_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                &laquo; Vorige
                </Link>
            </div>
            <div class="hidden md:flex space-x-1">
                <Link v-for="link in links" :key="link.url"
                    :href="`${link.url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="px-3 py-1 text-sm font-medium border rounded hover:border-gray-300 hover:text-gray-700"
                    :class="link.active
                        ? 'border-indigo-500 text-indigo-600'
                        : 'border-transparent text-gray-500'" preserve-scroll>
                {{ link.label }}
                </Link>
            </div>
            <div class="flex-1 text-right">
                <Link v-if="products.next_page_url"
                    :href="`${products.next_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                Volgende &raquo;
                </Link>
            </div>
        </nav>

        <p v-else class="text-center text-gray-500">Geen producten gevonden.</p>
    </div>
</template>

<script setup>
import {
    PencilSquareIcon,
    PlusCircleIcon,
    TrashIcon,
    XCircleIcon
} from '@heroicons/vue/24/outline'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import SearchComponent from '@/Components/UI/SearchComponent.vue'

const { products, search: initialSearch } = defineProps({
    products: { type: Object, required: true },
    search: { type: String, default: '' },
    brands: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] }
})

const searchTerm = ref(initialSearch)
const internalProducts = ref(products.data)
const links = products.links.filter(
    link => link.label !== '&laquo; Previous' && link.label !== 'Next &raquo;'
)

const addingProduct = ref(false)

const newProductForm = useForm({
    product_type_id: '',
    brand_id: '',
    model: '',
    description: '',
    start_sell: '',
    end_sell: '',
})

function onCreateSuccess() {
    const created = usePage().props.flash.extra
    internalProducts.value.push({ ...created, open: false })
    internalProducts.value.sort((a, b) => a.model.localeCompare(b.model))
    newProductForm.reset()
    addingProduct.value = false
}

const deleteProduct = (id) => {
    if (!confirm('Weet je zeker dat je dit product wilt verwijderen?')) return
    internalProducts.value = internalProducts.value.filter(p => p.id !== id)
    newProductForm.delete(`/products/${id}`, { preserveScroll: true })
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
    internalProducts.value.sort((a, b) => a.model.localeCompare(b.model))
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

</script>