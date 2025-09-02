<template>
    <div class="p-4 bg-white rounded-md" v-auto-animate>
        <!-- Header & Add Button -->
        <div class="sm:flex justify-between items-center flex-wrap mb-4">
            <div>
                <h1 class="text-base font-semibold">Keurpunten</h1>
                <p class="text-sm text-gray-700">Overzicht van alle keurpunten</p>
            </div>
            <button v-if="addingServiceCheck === false" @click="addingServiceCheck = true"
                class="cursor-pointer inline-flex items-center px-3 py-2 border border-green-900 text-green-900 bg-green-100 rounded-md text-sm">
                <PlusCircleIcon class="h-5 w-5 mr-1" />
                Voeg keurpunt toe
            </button>
        </div>

        <!-- Search -->
        <div class="mb-4 flex flex-wrap" v-if="!addingServiceCheck">
            <div class="w-full md:w-2/3">
                <label class="block text-sm font-medium">Zoek binnen keurpunten</label>
                <div class="mt-2 relative rounded-md shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <MagnifyingGlassIcon v-if="!inAction" class="h-5 w-5 text-gray-400" />
                        <ArrowPathIcon v-else class="h-5 w-5 text-gray-400 animate-spin" />
                    </div>
                    <input type="text" id="searchInput" v-model="searchTerm" :disabled="inAction"
                        placeholder="bijv. 'Valt de speling binnen de tolerantie'"
                        class="block w-full pl-10 pr-3 py-2 ring ring-gray-300 rounded-md focus:ring-indigo-600 focus:border-indigo-600 sm:text-sm" />
                </div>
            </div>
            <div class="ml-0 md:ml-4 mt-4 md:mt-0 flex-grow flex items-end">
                <div class="flex-grow">
                    <label class="block text-xs font-medium">Filter op type</label>
                    <ComboBox :options="productTypesForComboBox" v-model="productTypeToShow"
                        placeholder="Selecteer producttype" class="w-full mt-3" />
                </div>
                <XCircleIcon class="h-8 w-8 text-gray-400 cursor-pointer ml-2 mb-1"
                    @click="productTypeToShow = null; router.get('/servicechecks', {}, { preserveScroll: true })"
                    v-tooltip="'Reset filter op producttype'" />
            </div>
        </div>

        <!-- New ServiceCheck Form -->
        <div v-if="addingServiceCheck" class="mb-6 p-4 ring ring-gray-300 rounded-md relative">
            <div class="space-y-4">
                <!-- Product Type -->
                <div>
                    <label class="block text-sm font-medium">Producttype</label>
                    <div class="mt-1">
                        <ComboBox :options="productTypes" v-model="newServiceCheckForm.product_type_id"
                            placeholder="Selecteer producttype" :initialId="productTypes[0].id" />
                    </div>
                    <p v-if="newServiceCheckForm.errors.product_type_id" class="text-red-600 text-sm">
                        {{ newServiceCheckForm.errors.product_type_id }}
                    </p>
                </div>

                <!-- ServiceCheck Type -->
                <div>
                    <label class="block text-sm font-medium">Type</label>
                    <div class="mt-1">
                        <ComboBox :options="serviceCheckTypesForComboBox" v-model="newServiceCheckForm.type"
                            placeholder="Selecteer type" :initial-id="serviceCheckTypesForComboBox[0].id" />
                    </div>
                    <p v-if="newServiceCheckForm.errors.type" class="text-red-600 text-sm">
                        {{ newServiceCheckForm.errors.type }}
                    </p>
                </div>

                <!-- Name -->
                <TextInput v-model="newServiceCheckForm.name" label="Naam" :hasError="newServiceCheckForm.errors.name"
                    :errorMessage="newServiceCheckForm.errors.name" />
            </div>

            <div class="absolute top-2 right-2 flex space-x-2">
                <button
                    @click="newServiceCheckForm.post('/servicechecks', { preserveScroll: true, onSuccess: onCreateSuccess })"
                    class="px-3 py-1 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">
                    Opslaan
                </button>
                <XCircleIcon class="h-6 w-6 text-gray-400 cursor-pointer"
                    @click="newServiceCheckForm.reset(); addingServiceCheck = false" />
            </div>
        </div>

        <PaginationComponent v-if="internalServiceChecks.length" :paginator="serviceChecks"
            :params="{ search: searchTerm }" class="border-b border-gray-200 pb-2" />

        <!-- Table -->
        <div v-if="internalServiceChecks.length" class="-mx-4 mt-3 sm:-mx-0 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead class="hidden md:table-header-group">
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-semibold" v-if="productTypeToShow !== 0">Volgorde
                        </th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Naam</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Producttype</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Type</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Waarden</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" v-auto-animate>
                    <template v-for="(item, index) in internalServiceChecks" :key="item.id">
                        <tr class="grid md:table-row grid-cols-12 relative pt-5 md:pt-0"
                            :class="index % 2 === 1 ? 'bg-gray-100' : 'bg-white'">
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2"
                                v-if="productTypeToShow !== 0">
                                <span class="block md:hidden font-semibold text-xs">Volgorde</span>
                                <div v-if="item.open">
                                    <TextInput v-model="item.order" />
                                </div>
                                <span v-else>{{ item.order }}</span>
                            </td>
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2">
                                <span class="block md:hidden font-semibold text-xs">Naam</span>
                                <div v-if="item.open">
                                    <TextInput v-model="item.name" />
                                </div>
                                <span v-else>{{ item.name }}</span>
                            </td>
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2">
                                <span class="block md:hidden font-semibold text-xs">Producttype</span>
                                <div v-if="item.open">
                                    <ComboBox :options="productTypes" v-model="item.product_type_id"
                                        :initialId="item.product_type.id" />
                                </div>
                                <span v-else>{{ item.product_type.name }}</span>
                            </td>
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2">
                                <span class="block md:hidden font-semibold text-xs">Type keurpunt</span>
                                <div v-if="item.open">
                                    <ComboBox :options="serviceCheckTypesForComboBox" v-model="item.type"
                                        :initialId="item.type.name" />
                                </div>
                                <span v-else>{{ serviceCheckTypes[item.type] }}</span>
                            </td>
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2 relative pr-8 md:pr-4">
                                <span class="block md:hidden font-semibold text-xs">Opties</span>
                                {{ getValuesCellContent(item) }}
                                <AdjustmentsHorizontalIcon
                                    v-if="Object.keys(serviceCheckTypesWithOptions).includes(item.type) && !item.open"
                                    class="inline size-7 md:size-5 text-blue-300 absolute right-4 md:right-0 top-1/2 transform -translate-y-1/2 cursor-pointer"
                                    @click.stop="toggleRecordValueEdit(item.id)"
                                    v-tooltip="`Bewerk waarden voor ${item.name}`" />
                            </td>
                            <td
                                class="px-4 py-2 text-right text-sm font-medium absolute md:relative right-0 top-0 min-w-20">
                                <button v-if="!item.open" @click="toggleRecord(item.id)">
                                    <PencilSquareIcon
                                        class="inline size-7 md:size-5 text-gray-600 mr-2 mb-0 sm:mb-0 cursor-pointer"
                                        v-tooltip="'Bewerk dit keurpunt'" />
                                </button>
                                <button v-else @click="saveRecord(item)"
                                    class="text-green-600 hover:text-green-900 mr-2">
                                    Opslaan
                                </button>
                                <TrashIcon
                                    class="inline size-7 md:size-5 text-red-400 hover:text-red-600 cursor-pointer"
                                    @click.stop="deleteServiceCheck(item.id)" />
                            </td>
                        </tr>
                        <tr v-if="item.openValue && !item.open" :key="`${item.id}-values`"
                            :class="index % 2 === 1 ? 'bg-gray-100' : 'bg-white'">
                            <td colspan="5" class="px-4">
                                <h5 class="text-sm font-semibold mb-2">Bewerk of verwijder de waarden voor {{
                                    item.name
                                }}, of voeg een
                                    nieuwe toe
                                </h5>
                                <ServiceCheckValueListComponent v-model="item.values"
                                    :allServiceChecks="internalServiceChecks" :parentServiceCheckId="item.id" />
                                <div class="flex items-center">
                                    <div class="flex flex-grow">
                                        <TextInput v-model="serviceCheckValueForm.value"
                                            placeholder="Voeg nieuwe waarde toe" class="mb-2 w-full"
                                            :error-message="serviceCheckValueForm.errors.value"
                                            :has-error="serviceCheckValueForm.errors.value" />
                                    </div>
                                    <PlusCircleIcon class="size-7 text-green-600 cursor-pointer ml-2 mb-2"
                                        @click="() => { addnewServiceCheckValue(item.id) }"
                                        v-tooltip="`Voeg waarde '${serviceCheckValueForm.value}' toe`" />
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <PaginationComponent v-if="internalServiceChecks.length" :paginator="serviceChecks"
            :params="{ search: searchTerm }" class="border-t border-gray-200 pt-2" />

        <p v-else class="text-center text-gray-500">Geen service checks gevonden.</p>
    </div>
</template>

<script setup>
import {
    AdjustmentsHorizontalIcon,
    ArrowPathIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusCircleIcon,
    TrashIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { ref, watch, onMounted } from 'vue'
import debounce from 'lodash/debounce'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import ServiceCheckValueListComponent from '@/Components/ServiceCheckValueListComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

const {
    serviceChecks,
    search: initialSearch,
    productTypes,
    serviceCheckTypes,
    serviceCheckTypesWithOptions
} = defineProps({
    serviceChecks: { type: Object, required: true },
    search: { type: String, default: '' },
    productTypes: { type: Array, default: () => [] },
    serviceCheckTypes: { type: Array, default: () => [] },
    serviceCheckTypesWithOptions: { type: Object, default: () => ({}) },
})

const productTypesForComboBox = ref([{
    id: '0',
    name: 'Selecteer type',
}])
for (const type of productTypes) {
    productTypesForComboBox.value.push({
        id: type.id,
        name: type.name,
    })
}

const serviceCheckTypesForComboBox = ref([])
for (const type in serviceCheckTypes) {
    serviceCheckTypesForComboBox.value.push({
        id: type,
        name: serviceCheckTypes[type],
    })
}

const typeFromURL = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('onlyType')
    : null
const productTypeToShow = ref(Number(typeFromURL))

const getValuesCellContent = item => {
    if (Object.keys(serviceCheckTypesWithOptions).includes(item.type)) {
        return item.values.map(val => val.value).join(', ')
    }
}

const toggleRecordValueEdit = (id) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
        sc.open = false
        if (sc.id === id) {
            sc.openValue = !sc.openValue
        } else {
            sc.openValue = false
        }
        return sc
    })
}

const searchTerm = ref(initialSearch)
const internalServiceChecks = ref(serviceChecks.data)
// pagination handled by PaginationComponent

const inAction = ref(false)
const addingServiceCheck = ref(false)

const newServiceCheckForm = useForm({
    name: '',
    product_type_id: '',
    type: '',
})

const serviceCheckValueForm = useForm({
    value: '',
    service_check_id: '',
})

const addnewServiceCheckValue = (serviceCheckId) => {
    serviceCheckValueForm.service_check_id = serviceCheckId
    serviceCheckValueForm.post('/servicecheckvalues', {
        preserveScroll: true,
        onSuccess: () => {
            internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
                if (sc.id === serviceCheckId) {
                    sc.values.push({
                        id: usePage().props.flash.extra.id,
                        value: serviceCheckValueForm.value,
                    })
                }
                return sc
            })
            serviceCheckValueForm.reset()
        },
    })
}

function onCreateSuccess() {
    const created = usePage().props.flash.extra
    internalServiceChecks.value.push({ ...created, open: false })
    internalServiceChecks.value.sort((a, b) => a.order - b.order);
    newServiceCheckForm.reset()
    addingServiceCheck.value = false
}

const deleteServiceCheck = (id) => {
    if (!confirm('Weet je zeker dat je dit wilt verwijderen?')) return
    internalServiceChecks.value = internalServiceChecks.value.filter(
        (sc) => sc.id !== id
    )
    newServiceCheckForm.delete(`/servicechecks/${id}`, {
        preserveScroll: true,
    })
}

const toggleRecord = (id) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
        sc.openValue = false
        if (sc.open) {
            const updateForm = useForm({ ...sc })
            updateForm.patch(`/servicechecks/${sc.id}`, {
                preserveScroll: true,
            })
        }
        return { ...sc, open: sc.id === id ? !sc.open : false }
    })
}

const saveRecord = (sc) => {
    const form = useForm({ ...sc })
    form.patch(`/servicechecks/${sc.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            internalServiceChecks.value = internalServiceChecks.value.map((item) =>
                item.id === sc.id ? { ...item, open: false } : item
            )
            internalServiceChecks.value.sort((a, b) => a.order - b.order);
        },
    })
}

watch(productTypeToShow, (val) => {
    router.get('/servicechecks', { onlyType: val }, {
        preserveScroll: true,
    })
})

const searchServiceChecks = debounce((term) => {
    inAction.value = true
    localStorage.setItem('searchInitiated', 'true')
    router.get(`/servicechecks?search=${term}`, {}, { preserveScroll: true })
}, 300)

watch(searchTerm, searchServiceChecks)

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false
        localStorage.removeItem('searchInitiated')
        document.getElementById('searchInput')?.focus()
    }
})
</script>