<template>
    <IndexHeaderComponent title="Werkbonfases" subtitle="Overzicht en volgorde van werkbonfases"
        search-url="/serviceorderstages" search-label="Zoek binnen fases" search-placeholder="bijv. 'Voorbereiding'"
        :paginator="false" add-label="Voeg fase toe" @add="showStageDrawer = true" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalStages.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-1"></div>
                <div class="col-span-1">Volgorde</div>
                <div class="col-span-3">Naam</div>
                <div class="col-span-2 text-center">Gepland fase</div>
                <div class="col-span-2 text-center">Gesloten fase</div>
                <div class="col-span-1 text-center">Planbare fase</div>
                <div class="col-span-1 text-center">Na annuleren</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <draggable v-model="internalStages" handle=".draghandle" :animation="200" @change="onReorder">
                <div v-for="stage in internalStages" :key="stage.id" role="row"
                    class="p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                    <!-- Mobile layout -->
                    <div class="md:hidden">
                        <div class="flex items-center gap-2">
                            <Bars4Icon class="size-6 shrink-0 text-gray-500 cursor-move draghandle"
                                v-tooltip="'Sleep om de volgorde aan te passen'" />
                            <span class="w-5 shrink-0 text-gray-800 dark:text-slate-200">{{ stage.order }}</span>
                            <div class="flex-1 min-w-0">
                                <EditableTextField type="input" :decoration="false" :model-value="stage.name"
                                    @update="(val) => saveStage(stage.id, { name: val })" />
                            </div>
                            <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex shrink-0">
                                <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteStage(stage.id)"
                                    v-tooltip="'Verwijder deze fase'" />
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-4 gap-1">
                            <div class="flex flex-col items-center gap-1">
                                <span class="text-xs text-gray-500 text-center leading-tight">Gepland</span>
                                <SwitchComponent :model-value="stage.is_planned_state"
                                    @update:modelValue="(v) => saveStage(stage.id, { is_planned_state: v })" />
                            </div>
                            <div class="flex flex-col items-center gap-1">
                                <span class="text-xs text-gray-500 text-center leading-tight">Gesloten</span>
                                <SwitchComponent :model-value="stage.is_closed_state"
                                    @update:modelValue="(v) => saveStage(stage.id, { is_closed_state: v })" />
                            </div>
                            <div class="flex flex-col items-center gap-1">
                                <span class="text-xs text-gray-500 text-center leading-tight">Planbaar</span>
                                <SwitchComponent :model-value="stage.is_plannable_state"
                                    @update:modelValue="(v) => saveStage(stage.id, { is_plannable_state: v })" />
                            </div>
                            <div class="flex flex-col items-center gap-1">
                                <span class="text-xs text-gray-500 text-center leading-tight">Na annu.</span>
                                <SwitchComponent :model-value="stage.is_planning_cancelled_state"
                                    @update:modelValue="(v) => saveStage(stage.id, { is_planning_cancelled_state: v })" />
                            </div>
                        </div>
                    </div>
                    <!-- Desktop layout -->
                    <div class="hidden md:grid grid-cols-12 items-center">
                        <div class="col-span-1 flex items-center">
                            <Bars4Icon class="size-6 text-gray-500 cursor-move draghandle"
                                v-tooltip="'Sleep om de volgorde aan te passen'" />
                        </div>
                        <div class="col-span-1 text-gray-800 dark:text-slate-200">
                            {{ stage.order }}
                        </div>
                        <div class="col-span-3 pr-4">
                            <EditableTextField type="input" :decoration="false" :model-value="stage.name"
                                @update="(val) => saveStage(stage.id, { name: val })" />
                        </div>
                        <div class="col-span-2 flex items-center justify-center">
                            <SwitchComponent :model-value="stage.is_planned_state"
                                @update:modelValue="(v) => saveStage(stage.id, { is_planned_state: v })" />
                        </div>
                        <div class="col-span-2 flex items-center justify-center">
                            <SwitchComponent :model-value="stage.is_closed_state"
                                @update:modelValue="(v) => saveStage(stage.id, { is_closed_state: v })" />
                        </div>
                        <div class="col-span-1 flex items-center justify-center">
                            <SwitchComponent :model-value="stage.is_plannable_state"
                                @update:modelValue="(v) => saveStage(stage.id, { is_plannable_state: v })" />
                        </div>
                        <div class="col-span-1 flex items-center justify-center">
                            <SwitchComponent :model-value="stage.is_planning_cancelled_state"
                                @update:modelValue="(v) => saveStage(stage.id, { is_planning_cancelled_state: v })" />
                        </div>
                        <div class="col-span-1 flex justify-end">
                            <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
                                <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteStage(stage.id)"
                                    v-tooltip="'Verwijder deze fase'" />
                            </div>
                        </div>
                    </div>
                </div>
            </draggable>
            <div class="flex justify-between bg-white rounded-b-lavoro-sm p-4 dark:bg-slate-900">
                <PageRecordCountComponent :total="stages.total" :per-page="perPage" label="fases" />
                <PaginationComponent :paginator="stages" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <Bars4Icon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen fases gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="showStageDrawer" title="Nieuwe fase"
        subtitle="Vul een naam in om een nieuwe werkbonfase toe te voegen.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Naam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newStageForm.name" type="text" :hasError="Boolean(newStageForm.errors.name)"
                        :errorMessage="newStageForm.errors.name" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeStageDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewStage" :disabled="newStageForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import { Bars4Icon } from '@heroicons/vue/24/outline'
import { TrashIcon } from '@lucide/vue'
import TextInput from '@/Components/UI/TextInput.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

const { stages, perPage } = defineProps({
    stages: { type: Object, required: true },
    search: { type: String, default: '' },
    perPage: { type: Number, default: 25 },
})

const showStageDrawer = ref(false)
const newStageForm = useForm({ name: '' })

function submitNewStage() {
    newStageForm.post('/serviceorderstages', {
        preserveScroll: true,
        onSuccess: () => {
            showStageDrawer.value = false
            newStageForm.reset()
        },
    })
}

function closeStageDrawer() {
    showStageDrawer.value = false
    newStageForm.reset()
    newStageForm.clearErrors()
}

const internalStages = ref((stages.data || []).map(s => ({ ...s })))

watch(
    () => stages.data,
    (newData) => {
        internalStages.value = (newData || []).map(s => ({ ...s }))
    }
)

const reorderForm = useForm({ payload: [] })

function onReorder() {
    reorderForm.payload = internalStages.value.map((s, i) => ({ id: s.id, order: i + 1 }))
    reorderForm.post('/serviceorderstages/reorder', { preserveScroll: true })
}

function saveStage(id, payload) {
    router.patch(`/serviceorderstages/${id}`, payload, { preserveScroll: true })
}

function deleteStage(id) {
    if (!confirm('Weet je zeker dat je deze fase wilt verwijderen?')) return
    useForm({}).delete(`/serviceorderstages/${id}`, { preserveScroll: true })
}
</script>
