<template>
    <IndexHeaderComponent title="Werkbonfases" subtitle="Overzicht en volgorde van werkbonfases"
        search-url="/serviceorderstages" search-label="Zoek binnen fases"
        search-placeholder="bijv. 'Voorbereiding'"
        :paginator="stages" add-label="Voeg fase toe"
        @add="() => stageFormRef?.show()" />

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="stageFormRef" external-trigger action="/serviceorderstages"
            :fields="stageFields" add-button-label="Voeg fase toe" submit-label="Opslaan" />
    </div>

    <BoxComponent padding="md:mx-0 px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalStages.length" class="mt-3">
            <div class="hidden md:grid md:grid-cols-12 px-4 py-2 text-sm font-semibold text-left border-b border-gray-200 dark:border-slate-700">
                <div class="col-span-1 text-gray-900 dark:text-gray-300"></div>
                <div class="col-span-2 text-gray-900 dark:text-gray-300">Volgorde</div>
                <div class="col-span-7 text-gray-900 dark:text-gray-300">Naam</div>
                <div class="col-span-2"></div>
            </div>
            <draggable v-model="internalStages" item-key="id" handle=".draghandle" :animation="200" @change="onReorder">
                <template #item="{ element: stage }">
                    <div :key="stage.id"
                        class="odd:bg-white even:bg-gray-100 dark:odd:bg-slate-800 dark:even:bg-slate-900">
                        <div class="relative pt-5 md:pt-0 md:grid grid-cols-12 break-all">
                            <div class="flex items-center px-4 py-2 col-span-1">
                                <Bars4Icon class="size-6 text-gray-500 cursor-move draghandle"
                                    v-tooltip="'Sleep om de volgorde aan te passen'" />
                            </div>
                            <div class="flex flex-col px-4 py-2 col-span-2">
                                <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Volgorde</span>
                                <span class="text-gray-800 dark:text-slate-200">{{ stage.order }}</span>
                            </div>
                            <div class="flex flex-col px-4 py-2 col-span-7">
                                <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Naam</span>
                                <div v-if="stage.open">
                                    <TextInput v-model="stage.name" />
                                </div>
                                <span v-else class="text-gray-800 dark:text-slate-200">{{ stage.name }}</span>
                            </div>
                            <div class="px-4 py-2 flex items-start justify-end gap-2 text-sm font-medium col-span-2">
                                <button v-if="!stage.open" @click="toggleRecord(stage.id)"
                                    v-tooltip="'Bewerk deze fase'">
                                    <PencilSquareIcon class="size-6 text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100" />
                                </button>
                                <button v-else @click="saveRecord(stage)" class="text-green-600 hover:text-green-800"
                                    v-tooltip="'Opslaan'">
                                    <CheckIcon class="size-6" />
                                </button>
                                <button @click.stop="deleteStage(stage.id)" v-tooltip="'Verwijder deze fase'">
                                    <TrashIcon class="size-6 text-red-400 hover:text-red-600" />
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </draggable>
        </div>
        <PaginationComponent v-if="internalStages.length" :paginator="stages"
            class="border-t border-gray-200 dark:border-slate-700 pt-2" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen fases gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import {
    PencilSquareIcon,
    TrashIcon,
    CheckIcon,
    Bars4Icon,
} from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

const { stages } = defineProps({
    stages: { type: Object, required: true },
    search: { type: String, default: '' },
})

const stageFormRef = ref(null)
const stageFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const internalStages = ref(
    (stages.data || []).map(s => ({ ...s, open: false }))
)

watch(
    () => stages.data,
    (newData) => {
        const existingById = {}
        for (const s of internalStages.value) existingById[s.id] = s
        internalStages.value = (newData || []).map(s => ({
            ...s,
            open: existingById[s.id]?.open || false,
        }))
    }
)

const reorderForm = useForm({ payload: [] })

function onReorder() {
    reorderForm.payload = internalStages.value.map((s, i) => ({ id: s.id, order: i + 1 }))
    reorderForm.post('/serviceorderstages/reorder', { preserveScroll: true })
}

function toggleRecord(id) {
    internalStages.value = internalStages.value.map((s) => {
        if (s.open) {
            const updateForm = useForm({ name: s.name, order: s.order })
            updateForm.patch(`/serviceorderstages/${s.id}`, { preserveScroll: true })
        }
        return { ...s, open: s.id === id ? !s.open : false }
    })
}

function saveRecord(stage) {
    const form = useForm({ name: stage.name, order: stage.order })
    form.patch(`/serviceorderstages/${stage.id}`, { preserveScroll: true, preserveState: false })
}

function deleteStage(id) {
    if (!confirm('Weet je zeker dat je deze fase wilt verwijderen?')) return
    useForm({}).delete(`/serviceorderstages/${id}`, { preserveScroll: true })
}
</script>
