<template>
    <div :key="serviceChecks.total">
    <div class="p-4 bg-white rounded-md mb-3" v-auto-animate>
        <IndexHeaderComponent title="Keurpunten" subtitle="Overzicht van alle keurpunten" search-url="/servicechecks"
            search-label="Zoek binnen keurpunten" search-placeholder="bijv. 'Valt de speling binnen de tolerantie'"
            :search-other-params="{ onlyType: productTypeToShow }" add-label="Voeg keurpunt toe"
            :paginator="serviceChecks" @add="() => serviceCheckFormRef?.show()">
            <template #right>
                <div class="w-full flex items-end gap-2">
                    <div class="flex-grow mt-1">
                        <label class="block text-xs font-medium">Filter op type</label>
                        <ComboBox :options="productTypesForComboBox" v-model="productTypeToShow"
                            placeholder="Selecteer producttype" class="w-full mt-2" />
                    </div>
                    <button type="button"
                        class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600 mb-[2px]"
                        @click="productTypeToShow = null" v-tooltip="'Reset filter op producttype'">
                        <XCircleIcon class="h-5 w-5" />
                    </button>
                </div>
            </template>
        </IndexHeaderComponent>
    </div>

    <div class="mb-6" v-auto-animate>
        <CreateRecordForm ref="serviceCheckFormRef" external-trigger action="/servicechecks"
            :fields="serviceCheckFields" add-button-label="Voeg keurpunt toe" submit-label="Opslaan" />
    </div>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalServiceChecks.length" class="-mx-4 mt-3 sm:-mx-0 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead class="hidden md:table-header-group">
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-semibold" v-if="productTypeToShow !== 0">Volgorde
                        </th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Naam</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Producttypes</th>
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
                                <span class="block md:hidden font-semibold text-xs">Producttypes</span>
                                <div v-if="item.open">
                                    <ComboBox :options="productTypes" v-model="item.product_type_ids" multiple
                                        :initialIds="(item.product_types || []).map(pt => pt.id)"
                                        @update:modelValue="() => validateGroupSelection(item)" />
                                </div>
                                <span v-else>{{(item.product_types || []).map(pt => pt.name).join(', ')}}</span>
                            </td>
                            <td class="flex flex-col col-span-12 md:table-cell px-4 py-2">
                                <span class="block md:hidden font-semibold text-xs">Groep</span>
                                <div v-if="item.open">
                                    <ComboBox :options="getGroupsFor(item)" v-model="item.service_check_group_id"
                                        :initialId="item.service_check_group_id ?? null" placeholder="Geen groep"
                                        :key="`grp-${item.id}-${(item.product_type_ids || []).join(',')}`" />
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
                                <h5 class="text-sm font-semibold mb-2">Bewerk of verwijder de waarden voor {{ item.name
                                }}, of voeg een nieuwe toe</h5>
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
            class="border-t border-gray-200 pt-2" />

        <p v-else class="text-center text-gray-500 p-4">Geen service checks gevonden.</p>
    </BoxComponent>
    </div>
</template>

<script setup>
import {
    AdjustmentsHorizontalIcon,
    PencilSquareIcon,
    TrashIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline'
import { useForm } from '@inertiajs/vue3'
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

const groupsByProductType = ref({})
for (const g of groups) {
    const pts = (g.product_types || []).map(pt => pt.id)
    for (const ptId of pts) {
        if (!groupsByProductType.value[ptId]) groupsByProductType.value[ptId] = []
        groupsByProductType.value[ptId].push({ id: g.id, name: g.name })
    }
}
for (const pt of productTypes) {
    if (!groupsByProductType.value[pt.id]) {
        groupsByProductType.value[pt.id] = [{ id: null, name: 'Geen groep' }]
    } else if (!groupsByProductType.value[pt.id].find(o => o.id === null)) {
        groupsByProductType.value[pt.id].unshift({ id: null, name: 'Geen groep' })
    }
}

const productTypesForComboBox = ref([{ id: '0', name: 'Selecteer type' }])
for (const type of productTypes) {
    productTypesForComboBox.value.push({ id: type.id, name: type.name })
}

const serviceCheckTypesForComboBox = ref([])
for (const type in serviceCheckTypes) {
    serviceCheckTypesForComboBox.value.push({ id: type, name: serviceCheckTypes[type] })
}

const typeFromURL = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('onlyType') : null
const productTypeToShow = ref(Number(typeFromURL))

const getValuesCellContent = item => {
    if (Object.keys(serviceCheckTypesWithOptions).includes(item.type)) {
        return item.values.map(val => val.value).join(', ')
    }
}

const internalServiceChecks = ref(
    (serviceChecks.data || []).map(sc => ({
        ...sc,
        product_type_ids: (sc.product_types || []).map(pt => pt.id),
    }))
)

watch(
    () => serviceChecks.data,
    (newData) => {
        const existingById = {}
        for (const sc of internalServiceChecks.value) existingById[sc.id] = sc
        internalServiceChecks.value = (newData || []).map(sc => ({
            ...sc,
            product_type_ids: (sc.product_types || []).map(pt => pt.id),
            open: existingById[sc.id]?.open || false,
            openValue: existingById[sc.id]?.openValue || false,
        }))
    }
)

const serviceCheckFormRef = ref(null)
const serviceCheckFields = [
    { key: 'product_type_ids', label: 'Producttypes', type: 'combobox', options: productTypes, multiple: true, initialIds: productTypes.length ? [productTypes[0].id] : [] },
    { key: 'type', label: 'Type', type: 'combobox', options: serviceCheckTypesForComboBox.value, initialId: serviceCheckTypesForComboBox.value[0]?.id },
    { key: 'name', label: 'Naam', type: 'text' },
]

const serviceCheckValueForm = useForm({ value: '', service_check_id: '' })

const addnewServiceCheckValue = (serviceCheckId) => {
    serviceCheckValueForm.service_check_id = serviceCheckId
    serviceCheckValueForm.post('/servicecheckvalues', { preserveScroll: true })
}

const deleteServiceCheck = (id) => {
    if (!confirm('Weet je zeker dat je dit wilt verwijderen?')) return
    useForm({}).delete(`/servicechecks/${id}`, { preserveScroll: true })
}

const toggleRecord = (id) => {
    internalServiceChecks.value = internalServiceChecks.value.map((sc) => {
        sc.openValue = false
        if (sc.open) {
            const updateForm = useForm({ ...sc, service_check_group_id: sc.service_check_group_id ?? null })
            updateForm.patch(`/servicechecks/${sc.id}`, { preserveScroll: true })
        }
        return { ...sc, open: sc.id === id ? !sc.open : false }
    })
}

const saveRecord = (sc) => {
    const form = useForm({ ...sc, service_check_group_id: sc.service_check_group_id ?? null })
    form.patch(`/servicechecks/${sc.id}`, { preserveScroll: true, preserveState: false })
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

const getGroupsFor = (item) => {
    const selected = new Set(item?.product_type_ids || [])
    const list = []
    const seen = new Set()
    for (const g of groups) {
        const pts = (g.product_types || []).map(pt => pt.id)
        if (pts.some(id => selected.has(id))) {
            if (!seen.has(g.id)) {
                seen.add(g.id)
                list.push({ id: g.id, name: g.name })
            }
        }
    }
    list.unshift({ id: null, name: 'Geen groep' })
    return list
}

const validateGroupSelection = (item) => {
    const options = getGroupsFor(item)
    if (!options.find(o => o.id === item.service_check_group_id)) {
        item.service_check_group_id = null
        item.group = null
    }
}

// Navigation on filter changes is handled by SearchComponent via search-other-params.
</script>