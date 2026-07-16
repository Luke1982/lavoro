<template>
    <div>
        <div class="flex items-start sm:items-center justify-between mb-4">
            <div class="flex items-start sm:items-center gap-3">
                <div class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                    <PuzzlePieceIcon class="h-5 w-5 text-white" />
                </div>
                <div class="flex flex-col">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Machines</h2>
                    <p class="text-xs text-slate-400 dark:text-slate-400">Machines die onder dit contract vallen.</p>
                </div>
            </div>
            <button v-if="canCreate" type="button" @click="showAddForm = !showAddForm"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-lavoro-blue hover:bg-lavoro-blue/90 rounded-md transition-colors">
                <PlusIcon class="size-4" />
                <span class="hidden sm:inline">Machine toevoegen</span>
            </button>
        </div>

        <div v-auto-animate>
            <div v-if="showAddForm && canCreate"
                class="flex flex-col md:flex-row items-start gap-2 mb-4 p-4 rounded-lavoro-sm dark:bg-slate-800/50 border border-gray-200/70 dark:border-slate-700">
                <div class="flex flex-col flex-grow w-full">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Kies een machine</span>
                    <AssetSelectMenu :assets="availableAssets" v-model="assetToAdd" placeholder="Selecteer machine" needs-box />
                </div>
                <div v-if="managePerAsset" class="flex flex-col w-full md:w-48">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Frequentie</span>
                    <ComboBox :options="intervalOptions" v-model="addForm.frequency" />
                </div>
                <div v-if="managePerAsset && addForm.frequency === 'Aangepast (dagen)'" class="flex flex-col w-full md:w-32">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Elke ... dagen</span>
                    <TextInput v-model="addForm.frequency_days" type="number" />
                </div>
                <button @click="attachAsset" :disabled="!assetToAdd || addForm.processing"
                    class="w-full md:w-auto px-4 py-2 rounded-md text-sm font-medium transition-colors mt-4 md:mt-5.5 bg-lavoro-blue text-white hover:bg-lavoro-blue/90 disabled:bg-gray-300 disabled:dark:bg-slate-700 disabled:text-gray-500 disabled:cursor-not-allowed">
                    Toevoegen
                </button>
            </div>
        </div>

        <div v-if="assets.length > 0" class="border-1 rounded-lavoro-sm border-gray-200/70">
            <div class="hidden md:grid grid-cols-12 text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-400 border-b border-gray-200/70 bg-gray-50/60 pt-3 pb-4 dark:border-slate-700 mb-1">
                <div class="col-span-5 pl-4">Machine</div>
                <div class="col-span-5">Frequentie</div>
                <div class="col-span-2 text-right pr-2">Acties</div>
            </div>
            <div v-auto-animate>
                <div v-for="asset in assets" :key="asset.pivot.id"
                    class="grid grid-cols-12 py-3 items-center border-b border-gray-100 dark:border-slate-800 last:border-b-0 px-3 sm:px-1">
                    <component :is="hasPermission('asset.read') ? Link : 'div'"
                        :href="hasPermission('asset.read') ? `/assets/${asset.id}` : undefined"
                        class="col-span-11 md:col-span-5 flex flex-col sm:pl-3 min-w-0"
                        :class="{ 'hover:underline': hasPermission('asset.read') }">
                        <span class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">
                            {{ assetDisplayName(asset) }}
                        </span>
                        <span v-if="asset.serial_number" class="text-xs text-gray-400 dark:text-slate-500">
                            Serienummer: {{ asset.serial_number }}
                        </span>
                    </component>
                    <div class="col-span-12 md:col-span-5 mt-2 md:mt-0">
                        <template v-if="managePerAsset">
                            <template v-if="canUpdate">
                                <ComboBox :options="intervalOptions" :model-value="asset.pivot.frequency"
                                    @update:model-value="val => updateFrequency(asset, { frequency: val, frequency_days: val === 'Aangepast (dagen)' ? asset.pivot.frequency_days : null })" />
                                <div v-if="asset.pivot.frequency === 'Aangepast (dagen)'" class="mt-2" v-auto-animate>
                                    <TextInput type="number" :model-value="asset.pivot.frequency_days"
                                        @update:model-value="val => updateFrequencyDaysDebounced(asset, val)"
                                        placeholder="Aantal dagen" />
                                </div>
                            </template>
                            <span v-else class="text-sm text-gray-700 dark:text-slate-300">{{ asset.pivot.frequency || '—' }}</span>
                        </template>
                        <span v-else class="text-sm text-gray-500 dark:text-slate-400 italic">Contractfrequentie</span>
                    </div>
                    <div class="col-span-1 md:col-span-2 flex justify-end pr-0 sm:pr-2">
                        <TrashIcon v-if="canDelete"
                            class="size-10 sm:size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer transition-colors"
                            @click="detachAsset(asset)" v-tooltip="'Machine loskoppelen'" />
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500 italic">Nog geen machines gekoppeld.</p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { debounce } from 'lodash'
import { PuzzlePieceIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import AssetSelectMenu from '@/Components/UI/AssetSelectMenu.vue'
import { hasPermission, mapAssetForSelect } from '@/Utilities/Utilities'

const props = defineProps({
    maintenanceContractId: { type: Number, required: true },
    assets: { type: Array, default: () => [] },
    customerAssets: { type: Array, default: () => [] },
    managePerAsset: { type: Boolean, default: false },
    intervalOptions: { type: Array, default: () => [] },
})

const canCreate = computed(() => hasPermission('assetable.create.maintenancecontract'))
const canUpdate = computed(() => hasPermission('assetable.update.maintenancecontract'))
const canDelete = computed(() => hasPermission('assetable.delete.maintenancecontract'))

const showAddForm = ref(false)
const assetToAdd = ref(null)

function assetDisplayName(asset) {
    if (!asset.product) return asset.serial_number || `Machine #${asset.id}`
    return `${asset.product.brand?.name ?? ''} ${asset.product.model ?? ''}`.trim() || asset.serial_number
}

const attachedAssetIds = computed(() => props.assets.map(a => a.id))
const availableAssets = computed(() =>
    props.customerAssets
        .filter(a => !attachedAssetIds.value.includes(a.id))
        .map(mapAssetForSelect)
)

const addForm = useForm({ frequency: null, frequency_days: null })

function attachAsset() {
    if (!assetToAdd.value) return
    addForm.post(`/maintenancecontracts/${props.maintenanceContractId}/assets/${assetToAdd.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showAddForm.value = false
            assetToAdd.value = null
            addForm.reset()
        },
    })
}

function updateFrequency(asset, payload) {
    useForm(payload).put(
        `/maintenancecontracts/${props.maintenanceContractId}/assets/${asset.pivot.id}`,
        { preserveScroll: true }
    )
}

const updateFrequencyDaysDebounced = debounce((asset, days) => {
    updateFrequency(asset, { frequency_days: days })
}, 500)

function detachAsset(asset) {
    useForm({}).delete(
        `/maintenancecontracts/${props.maintenanceContractId}/assets/${asset.pivot.id}`,
        { preserveScroll: true }
    )
}
</script>
