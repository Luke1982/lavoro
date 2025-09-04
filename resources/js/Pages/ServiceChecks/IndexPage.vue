<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3" v-auto-animate>
        <IndexHeaderComponent title="Keurpunten" subtitle="Overzicht van alle keurpunten" v-model="searchTerm"
            search-url="/servicechecks" search-label="Zoek binnen keurpunten"
            search-placeholder="bijv. 'Valt de speling binnen de tolerantie'"
            :search-other-params="{ onlyType: productTypeToShow }" add-label="Voeg keurpunt toe"
            :paginator="serviceChecks" :pagination-params="{ search: searchTerm }"
            @add="() => serviceCheckFormRef?.show()">
            <template #right>
                <div class="w-full flex items-end gap-2">
                    <div class="flex-grow mt-1">
                        <label class="block text-xs font-medium">Filter op type</label>
                        <ComboBox :options="productTypesForComboBox" v-model="productTypeToShow"
                            placeholder="Selecteer producttype" class="w-full mt-2" />
                    </div>
                    <button type="button" class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600 mb-[2px]"
                        @click="productTypeToShow = null; router.get('/servicechecks', { search: searchTerm }, { preserveScroll: true })"
                        v-tooltip="'Reset filter op producttype'">
                        <XCircleIcon class="h-5 w-5" />
                    </button>
                </div>
            </template>
        </IndexHeaderComponent>
    </div>

    <!-- Form box -->
    <div class="mb-6" v-auto-animate>
        <CreateRecordForm ref="serviceCheckFormRef" external-trigger action="/servicechecks"
            :fields="serviceCheckFields" add-button-label="Voeg keurpunt toe" submit-label="Opslaan" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalServiceChecks.length" class="-mx-4 mt-3 sm:-mx-0 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead class="hidden md:table-header-group">
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-semibold" v-if="productTypeToShow !== 0">Volgorde
                        </th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Naam</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Producttype</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Groep</th>
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
                                        :initialId="item.product_type.id"
                                        @update:modelValue="() => { item.service_check_group_id = null }" />
                                </div>
                                <span v-else>{{ item.product_type.name }}</span>
                            </td>
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2">
                                <span class="block md:hidden font-semibold text-xs">Groep</span>
                                <div v-if="item.open">
                                    <ComboBox :options="groupsByProductType[item.product_type_id] || []"
                                        v-model="item.service_check_group_id" :initialId="item.group?.id || null"
                                        placeholder="Geen groep" />
                                </div>
                                <span v-else>{{ item.group?.name || '—' }}</span>
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

        <p v-else class="text-center text-gray-500 p-4">Geen service checks gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import {
    AdjustmentsHorizontalIcon,
    PencilSquareIcon,
    TrashIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline'
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import ServiceCheckValueListComponent from '@/Components/ServiceCheckValueListComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import BoxComponent from '@/Components/BoxComponent.vue'

const {
    serviceChecks,
    search: initialSearch,
    productTypes,
    serviceCheckTypes,
    serviceCheckTypesWithOptions,
    groups
} = defineProps({
    serviceChecks: { type: Object, required: true },
    search: { type: String, default: '' },
    productTypes: { type: Array, default: () => [] },
    serviceCheckTypes: { type: Array, default: () => [] },
    serviceCheckTypesWithOptions: { type: Object, default: () => ({}) },
    groups: { type: Array, default: () => [] },
})

// Map groups per product type for filtered comboboxes
const groupsByProductType = ref({})
for (const g of groups) {
    if (!groupsByProductType.value[g.product_type_id]) groupsByProductType.value[g.product_type_id] = []
    groupsByProductType.value[g.product_type_id].push({ id: g.id, name: g.name })
}
// Prepend a null option per product type for 'Geen groep'
for (const ptId in groupsByProductType.value) {
    const list = groupsByProductType.value[ptId]
    if (!list.find(o => o.id === null)) list.unshift({ id: null, name: 'Geen groep' })
}
// Ensure all product types have at least the null option
for (const pt of productTypes) {
    if (!groupsByProductType.value[pt.id]) {
        groupsByProductType.value[pt.id] = [{ id: null, name: 'Geen groep' }]
    }
}

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

const serviceCheckFormRef = ref(null)
// Build dynamic group options for the create form based on selected product type
const serviceCheckFields = [
    { key: 'product_type_id', label: 'Producttype', type: 'combobox', options: productTypes, initialId: productTypes[0]?.id },
    { key: 'type', label: 'Type', type: 'combobox', options: serviceCheckTypesForComboBox.value, initialId: serviceCheckTypesForComboBox.value[0]?.id },
    { key: 'name', label: 'Naam', type: 'text' },
]

const serviceCheckValueForm = useForm({
    value: '',
    service_check_id: '',
})

const addnewServiceCheckValue = (serviceCheckId) => {
    serviceCheckValueForm.service_check_id = serviceCheckId
    serviceCheckValueForm.post('/servicecheckvalues', {
        preserveScroll: true,
    })
}

// Creation handled by backend redirect; no client-side mutations needed.

const deleteServiceCheck = (id) => {
    if (!confirm('Weet je zeker dat je dit wilt verwijderen?')) return
    useForm({}).delete(`/servicechecks/${id}`, {
        preserveScroll: true,
    })
}

const toggleRecord = (id) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
        sc.openValue = false
        if (sc.open) {
            const updateForm = useForm({ ...sc, service_check_group_id: sc.service_check_group_id ?? null })
            updateForm.patch(`/servicechecks/${sc.id}`, {
                preserveScroll: true,
            })
        }
        return { ...sc, open: sc.id === id ? !sc.open : false }
    })
}

const saveRecord = (sc) => {
    const form = useForm({ ...sc, service_check_group_id: sc.service_check_group_id ?? null })
    form.patch(`/servicechecks/${sc.id}`, {
        preserveScroll: true,
    })
}

watch(productTypeToShow, (val) => {
    router.get('/servicechecks', { onlyType: val, search: searchTerm.value }, { preserveScroll: true })
})

</script>