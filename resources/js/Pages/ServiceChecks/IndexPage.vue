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
        <div class="mb-4" v-if="!addingServiceCheck">
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

        <!-- New ServiceCheck Form -->
        <div v-if="addingServiceCheck" class="mb-6 p-4 ring ring-gray-300 rounded-md relative">
            <div class="space-y-4">
                <!-- Product Type -->
                <div>
                    <label class="block text-sm font-medium">Producttype</label>
                    <div class="mt-1">
                        <ComboBox :options="productTypes" v-model="newServiceCheckForm.product_type_id"
                            placeholder="Selecteer producttype" />
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
                            placeholder="Selecteer type" />
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

        <!-- Top Pagination -->
        <nav v-if="internalServiceChecks.length"
            class="flex items-center justify-between border-b border-gray-200 px-4 pb-2 sm:px-0">
            <div class="flex-1">
                <Link v-if="serviceChecks.prev_page_url"
                    :href="`${serviceChecks.prev_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                &laquo; Vorige
                </Link>
            </div>
            <div class="hidden md:flex space-x-1">
                <Link v-for="link in links" :key="link.url"
                    :href="`${link.url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="px-3 py-1 text-sm font-medium border rounded hover:border-gray-300 hover:text-gray-700"
                    :class="link.active ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'"
                    preserve-scroll>
                {{ link.label }}
                </Link>
            </div>
            <div class="flex-1 text-right">
                <Link v-if="serviceChecks.next_page_url"
                    :href="`${serviceChecks.next_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                Volgende &raquo;
                </Link>
            </div>
        </nav>

        <!-- Table -->
        <div v-if="internalServiceChecks.length" class="-mx-4 mt-3 sm:-mx-0 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Naam</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Producttype</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Type</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Waarden</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" v-auto-animate>
                    <template v-for="item in internalServiceChecks" :key="item.id">
                        <tr>
                            <td class="px-4 py-2">
                                <div v-if="item.open">
                                    <TextInput v-model="item.name" />
                                </div>
                                <span v-else>{{ item.name }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <div v-if="item.open">
                                    <ComboBox :options="productTypes" v-model="item.product_type_id"
                                        :initialId="item.product_type.id" />
                                </div>
                                <span v-else>{{ item.product_type.name }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <div v-if="item.open">
                                    <ComboBox :options="serviceCheckTypesForComboBox" v-model="item.type"
                                        :initialId="item.type.name" />
                                </div>
                                <span v-else>{{ serviceCheckTypes[item.type] }}</span>
                            </td>
                            <td class="px-4 py-2 relative">
                                {{ getValuesCellContent(item) }}
                                <AdjustmentsHorizontalIcon
                                    v-if="Object.keys(serviceCheckTypesWithOptions).includes(item.type)"
                                    class="inline h-5 w-5 text-blue-300 absolute right-0 top-1/2 transform -translate-y-1/2 cursor-pointer"
                                    @click.stop="toggleRecordValueEdit(item.id)"
                                    v-tooltip="`Bewerk waarden voor ${item.name}`" />
                            </td>
                            <td class="px-4 py-2 text-right text-sm font-medium">
                                <button v-if="!item.open" @click="toggleRecord(item.id)">
                                    <PencilSquareIcon class="inline h-5 w-5 text-gray-600 mr-2 cursor-pointer"
                                        v-tooltip="'Bewerk dit keurpunt'" />
                                </button>
                                <button v-else @click="saveRecord(item)"
                                    class="text-green-600 hover:text-green-900 mr-2">
                                    Opslaan
                                </button>
                                <TrashIcon class="inline h-5 w-5 text-red-400 hover:text-red-600 cursor-pointer"
                                    @click.stop="deleteServiceCheck(item.id)" />
                            </td>
                        </tr>
                        <tr v-if="item.openValue" :key="`${item.id}-values`">
                            <td colspan="5" class="px-4 py-">
                                <h5 class="text-sm font-semibold mb-2">Bewerk of verwijder de waarden voor {{ item.name
                                    }}, of voeg een
                                    nieuwe toe
                                </h5>
                                <ServiceCheckValueComponent v-for="value in item.values" :key="value.id"
                                    :scValue="value" class="w-full mb-2" @delete="id => removeSCValue(item.id, id)" />
                                <div class="flex items-center">
                                    <div class="flex flex-grow">
                                        <TextInput v-model="newServiceCheckValueForm.value"
                                            placeholder="Voeg nieuwe waarde toe" class="mb-2 w-full" />
                                    </div>
                                    <PlusCircleIcon class="size-7 text-green-600 cursor-pointer ml-2 mb-2"
                                        @click="() => { addnewServiceCheckValue(item.id) }"
                                        v-tooltip="`Voeg waarde '${newServiceCheckValueForm.value}' toe`" />
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Bottom Pagination -->
        <nav v-if="internalServiceChecks.length"
            class="flex items-center justify-between border-t border-gray-200 px-4 pt-2 sm:px-0">
            <div class="flex-1">
                <Link v-if="serviceChecks.prev_page_url"
                    :href="`${serviceChecks.prev_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                &laquo; Vorige
                </Link>
            </div>
            <div class="hidden md:flex space-x-1">
                <Link v-for="link in links" :key="link.url"
                    :href="`${link.url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="px-3 py-1 text-sm font-medium border rounded hover:border-gray-300 hover:text-gray-700"
                    :class="link.active ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'"
                    preserve-scroll>
                {{ link.label }}
                </Link>
            </div>
            <div class="flex-1 text-right">
                <Link v-if="serviceChecks.next_page_url"
                    :href="`${serviceChecks.next_page_url}${searchTerm ? `&search=${searchTerm}` : ''}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
                Volgende &raquo;
                </Link>
            </div>
        </nav>

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
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref, watch, onMounted } from 'vue'
import debounce from 'lodash/debounce'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import ServiceCheckValueComponent from '@/Components/ServiceCheckValueComponent.vue'

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

const serviceCheckTypesForComboBox = ref([])
for (const type in serviceCheckTypes) {
    serviceCheckTypesForComboBox.value.push({
        id: type,
        name: serviceCheckTypes[type],
    })
}

const getValuesCellContent = item => {
    if (Object.keys(serviceCheckTypesWithOptions).includes(item.type)) {
        return item.values.map(val => val.value).join(', ')
    }
}

const toggleRecordValueEdit = (id) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
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
const links = serviceChecks.links.filter(
    (link) => link.label !== '&laquo; Previous' && link.label !== 'Next &raquo;'
)

const removeSCValue = (serviceCheckId, valueId) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
        if (sc.id === serviceCheckId) {
            sc.values = sc.values.filter((v) => v.id !== valueId)
        }
        return sc
    })
}

const inAction = ref(false)
const addingServiceCheck = ref(false)

const newServiceCheckForm = useForm({
    name: '',
    product_type_id: '',
    type: '',
})

const newServiceCheckValueForm = useForm({
    value: '',
    service_check_id: '',
})

const addnewServiceCheckValue = (serviceCheckId) => {
    if (!newServiceCheckValueForm.value.trim()) {
        alert('Voer een waarde in.')
        return
    }
    newServiceCheckValueForm.service_check_id = serviceCheckId
    newServiceCheckValueForm.post('/servicecheckvalues', {
        preserveScroll: true,
        onSuccess: () => {
            internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
                if (sc.id === serviceCheckId) {
                    sc.values.push({
                        id: usePage().props.flash.extra.id,
                        value: newServiceCheckValueForm.value,
                    })
                }
                return sc
            })
            newServiceCheckValueForm.reset()
        },
    })
}

function onCreateSuccess() {
    const created = usePage().props.flash.extra
    internalServiceChecks.value.push({ ...created, open: false })
    internalServiceChecks.value.sort((a, b) =>
        a.name.localeCompare(b.name)
    )
    newServiceCheckForm.reset()
    addingServiceCheck.value = false
}

const deleteServiceCheck = (id) => {
    if (!confirm('Weet je zeker dat je dit wilt verwijderen?')) return
    internalServiceChecks.value = internalServiceChecks.value.filter(
        (sc) => sc.id !== id
    )
    newServiceCheckForm.delete(`/ servicechecks / ${id} `, {
        preserveScroll: true,
    })
}

const toggleRecord = (id) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
        if (sc.open) {
            const updateForm = useForm({ ...sc })
            updateForm.patch(`/ servicechecks / ${sc.id} `, {
                preserveScroll: true,
            })
        }
        return { ...sc, open: sc.id === id ? !sc.open : false }
    })
}

const saveRecord = (sc) => {
    const form = useForm({ ...sc })
    form.patch(`/ servicechecks / ${sc.id} `, {
        preserveScroll: true,
        onSuccess: () => {
            internalServiceChecks.value = internalServiceChecks.value.map((item) =>
                item.id === sc.id ? { ...item, open: false } : item
            )
        },
    })
}

const searchServiceChecks = debounce((term) => {
    inAction.value = true
    localStorage.setItem('searchInitiated', 'true')
    router.get(`/ servicechecks ? search = ${term} `, {}, { preserveScroll: true })
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