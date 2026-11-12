<template>
    <div>
        <SectionHeader :icon="Package" title="Materialen"
            subtitle="Overzicht van gebruikte materialen voor deze bon." chapter="materials">
            <template v-if="canSeeFinancials || (canCreate && !isClosed)" #actions>
                <button v-if="canSeeFinancials" type="button" @click="showFinancial = !showFinancial"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 p-1"
                    v-tooltip="showFinancial ? 'Verberg prijzen' : 'Toon prijzen'">
                    <EyeSlashIcon v-if="showFinancial" class="size-5" />
                    <EyeIcon v-else class="size-5" />
                </button>
                <button v-if="canCreate && !isClosed" type="button" @click="showAddForm = !showAddForm"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-lavoro-blue hover:bg-lavoro-blue/90 rounded-md transition-colors">
                    <PlusIcon class="size-4" />
                    <span class="hidden sm:inline">Materiaal toevoegen</span>
                </button>
            </template>
        </SectionHeader>

        <div v-auto-animate>
            <MaterialPicker v-if="showAddForm && canCreate && !isClosed" :endpoint="base" :all-materials="allMaterials"
                :materials-use-ajax="materialsUseAjax" :categories="categories" :usage-units="usageUnits"
                :sent-to-administration="sentToAdministration" :show-unforseen="showUnforseen"
                @added="showAddForm = false" />
        </div>

        <FreeformMaterialForm v-if="canCreateFreeform && !isClosed && !sentToAdministration" :endpoint="base"
            :show-unforseen="showUnforseen" />

        <!-- Sent-to-admin warning -->
        <div v-if="sentToAdministration"
            class="mb-4 p-3 rounded border border-amber-400 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300 text-sm font-semibold">
            Deze order is naar de administratie verzonden. Materialen kunnen niet meer worden aangepast.
        </div>

        <!-- Table -->
        <div v-if="materials.length > 0 || freeformMaterials.length > 0"
            class="border-1 rounded-lavoro-sm border-gray-200/70">
            <!-- Column headers (hidden on mobile) -->
            <div class="hidden md:grid text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-400 border-b border-gray-200/70 bg-gray-50/60 pt-3 pb-4 dark:border-slate-700 mb-1"
                :class="showFinancial ? 'grid-cols-12' : 'grid-cols-12'">
                <div :class="(showFinancial && showUnforseen) ? 'col-span-4' : 'col-span-5'" class="pl-17">Materiaal
                </div>
                <div :class="showFinancial ? 'col-span-2' : (showUnforseen ? 'col-span-5' : 'col-span-6')">Aantal</div>
                <div v-if="showUnforseen" class="col-span-1 text-center">Onv.</div>
                <div v-if="showFinancial" class="col-span-2 pl-3">Prijs per stuk</div>
                <div v-if="showFinancial" class="col-span-2">Totaal</div>
                <div class="col-span-1 text-right pr-2">Acties</div>
            </div>

            <!-- Rows -->
            <div v-auto-animate>
                <div v-for="material in materials" :key="material.pivot.id"
                    class="relative grid grid-cols-12 pt-8 pb-3 md:py-3 items-center border-b border-gray-100 dark:border-slate-800 last:border-b-0 px-3 sm:px-1">

                    <!-- Icon + name + code -->
                    <div :class="(showFinancial && showUnforseen) ? 'md:col-span-4' : 'md:col-span-5'"
                        class="col-span-12 flex items-center gap-3 sm:pl-3">
                        <div
                            class="w-10 h-10 flex items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/30 flex-none">
                            <component :is="getMaterialIcon(material)"
                                class="size-5 text-indigo-500 dark:text-indigo-400" />
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">{{
                                material.name }}</span>
                            <span v-if="material.code" class="text-xs text-gray-400 dark:text-slate-500">Artikelnummer:
                                {{ material.code }}</span>
                            <span v-if="isForeign(material) && material.task_label"
                                class="mt-0.5 inline-flex w-fit items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-900/30 px-2 py-0.5 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">
                                Taak: {{ material.task_label }}
                            </span>
                        </div>
                    </div>

                    <!-- Quantity -->
                    <div :class="showFinancial ? 'col-span-12 md:col-span-2' : (showUnforseen ? 'col-span-12 md:col-span-5' : 'col-span-12 md:col-span-6')"
                        class="flex items-center mt-2 md:mt-0">
                        <template v-if="!locked && canEditLine(material)">
                            <EditableTextField inputType="number" v-model="material.pivot.quantity" class="w-full md:w-20"
                                :error="quantityError(material)"
                                @update="val => updateLine(material, { quantity: Number(val) })">
                                <template #display>
                                    <span class="text-xs">{{ material.pivot.quantity }}{{ material.usage_unit ? ' ' +
                                        material.usage_unit.name
                                        : '' }}</span>
                                </template>
                            </EditableTextField>
                        </template>
                        <span v-else class="text-sm text-gray-700 dark:text-slate-300">
                            {{ material.pivot.quantity }}{{ material.usage_unit ? ' ' + material.usage_unit.name : '' }}
                        </span>
                    </div>

                    <!-- Unforseen toggle -->
                    <div v-if="showUnforseen"
                        class="col-span-12 md:col-span-1 flex justify-between sm:justify-center mt-2 md:mt-0 items-center py-2 sm:py-0 border-b md:border-0 border-gray-200/70 dark:border-slate-700">
                        <span class="text-xs text-slate-500 font-medium block sm:hidden">Onvoorzien</span>
                        <SwitchComponent :model-value="!!material.pivot.unforseen"
                            :disabled="locked || !canEditLine(material)"
                            @update:model-value="val => updateLine(material, { unforseen: val })"
                            v-tooltip="material.pivot.unforseen ? 'Onvoorzien' : 'Voorzien'" />
                    </div>

                    <!-- Price per unit -->
                    <div v-if="showFinancial" class="col-span-6 md:col-span-2 mt-2 md:mt-0 pl-0 sm:pl-3">
                        <span class="text-xs text-slate-500 font-medium block sm:hidden">Prijs per {{
                            material.usage_unit ? ' ' +
                                material.usage_unit.name
                                : 'eenheid' }}</span>
                        <span class="text-sm text-gray-700 dark:text-slate-300">{{ nlCurrency(material.price) }}</span>
                    </div>

                    <!-- Line total -->
                    <div v-if="showFinancial" class="col-span-5 md:col-span-2 mt-2 md:mt-0">
                        <span class="text-xs text-slate-500 font-medium block sm:hidden">Totaal</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-slate-100">
                            {{ nlCurrency(Number(material.pivot.quantity) * Number(material.price)) }}
                        </span>
                    </div>

                    <!-- Delete -->
                    <div class="absolute top-2 right-2 md:static md:col-span-1 flex justify-end pr-0 sm:pr-2">
                        <TrashIcon v-if="!locked && canRemoveLine(material)"
                            class="size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer transition-colors"
                            @click="removeLine(material)"
                            v-tooltip="'Verwijder dit materiaal van de werkbon'" />
                    </div>
                </div>

                <!-- Freeform rows -->
                <div v-for="freeform in freeformMaterials" :key="'freeform-' + freeform.id"
                    class="relative grid grid-cols-12 pt-8 pb-3 md:py-3 items-center border-b border-gray-100 dark:border-slate-800 last:border-b-0 px-3 sm:px-1">
                    <div :class="(showFinancial && showUnforseen) ? 'md:col-span-4' : 'md:col-span-5'"
                        class="col-span-12 flex items-start gap-3 sm:pl-3">
                        <div
                            class="w-10 h-10 flex items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/30 flex-none">
                            <PencilIcon class="size-5 text-indigo-500 dark:text-indigo-400" />
                        </div>
                        <div class="flex flex-grow flex-col min-w-0">
                            <template v-if="!locked && canEditLine(freeform)">
                                <EditableTextField v-model="freeform.description"
                                    @update="val => updateLine(freeform, { description: val })">
                                    <template #display>
                                        <span
                                            class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">{{
                                                freeform.description }}</span>
                                    </template>
                                </EditableTextField>
                            </template>
                            <span v-else class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">{{
                                freeform.description }}</span>
                            <span class="text-xs text-gray-400 dark:text-slate-500">Vrije regel</span>
                            <span v-if="isForeign(freeform) && freeform.task_label"
                                class="mt-0.5 inline-flex w-fit items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-900/30 px-2 py-0.5 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">
                                Taak: {{ freeform.task_label }}
                            </span>
                        </div>
                    </div>
                    <div :class="showFinancial ? 'col-span-12 md:col-span-2' : (showUnforseen ? 'col-span-12 md:col-span-5' : 'col-span-12 md:col-span-6')"
                        class="flex items-start mt-2 md:mt-0">
                        <template
                            v-if="!locked && canEditLine(freeform)">
                            <EditableTextField inputType="number" v-model="freeform.quantity" class="w-full md:w-20"
                                @update="val => updateLine(freeform, { quantity: Number(val) })">
                                <template #display>
                                    <span class="text-xs">{{ freeform.quantity }}</span>
                                </template>
                            </EditableTextField>
                        </template>
                        <span v-else class="text-sm text-gray-700 dark:text-slate-300">{{ freeform.quantity }}</span>
                    </div>
                    <div v-if="showUnforseen"
                        class="col-span-12 md:col-span-1 flex justify-between sm:justify-center mt-2 md:mt-0 items-center py-2 sm:py-0 border-b md:border-0 border-gray-200/70 dark:border-slate-700">
                        <span class="text-xs text-slate-500 font-medium block sm:hidden">Onvoorzien</span>
                        <SwitchComponent :model-value="!!freeform.unforseen"
                            :disabled="locked || !canEditLine(freeform)"
                            @update:model-value="val => updateLine(freeform, { unforseen: val })"
                            v-tooltip="freeform.unforseen ? 'Onvoorzien' : 'Voorzien'" />
                    </div>
                    <div v-if="showFinancial" class="col-span-6 md:col-span-2 mt-2 md:mt-0 pl-0 sm:pl-3">
                        <span class="text-sm text-gray-400 dark:text-slate-500">—</span>
                    </div>
                    <div v-if="showFinancial" class="col-span-5 md:col-span-2 mt-2 md:mt-0">
                        <span class="text-sm text-gray-400 dark:text-slate-500">—</span>
                    </div>
                    <div class="absolute top-2 right-2 md:static md:col-span-1 flex justify-end pr-0 sm:pr-2">
                        <TrashIcon
                            v-if="!locked && canRemoveLine(freeform)"
                            class="size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer transition-colors"
                            @click="removeLine(freeform)" v-tooltip="'Verwijder deze vrije regel'" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div
            class="flex flex-col sm:flex-row items-center justify-between mt-3 font-semibold rounded-lavoro-sm bg-gray-100/50 py-5 px-3 text-xs text-gray-500 dark:text-slate-400">
            <span v-if="showUnforseen" class="mb-2 sm:mb-0">
                {{ totalMaterialsCount }} materialen,
                {{ forseenCount }} voorzien en
                {{ unforseenCount }} onvoorzien
            </span>
            <span v-else>{{ totalMaterialsCount }} materialen</span>
            <span v-if="showFinancial && showUnforseen" class="flex flex-col items-end gap-1">
                <span>Voorzien <span class="text-lg text-gray-700 dark:text-slate-200 ml-2">{{
                    nlCurrency(forseenSubtotal)
                        }}</span></span>
                <span>Onvoorzien <span class="text-lg text-gray-700 dark:text-slate-200 ml-2">{{
                    nlCurrency(unforseenSubtotal)
                        }}</span></span>
            </span>
            <span v-else-if="showFinancial">
                Totaal materialen
                <span class="text-lg text-gray-700 dark:text-slate-200 ml-3">{{ nlCurrency(subtotal) }}</span>
            </span>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Package, Plus as PlusIcon, Trash2 as TrashIcon, Pencil as PencilIcon } from '@lucide/vue'
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { getIconByName } from '@/Utilities/lucideIconMap.js'
import { nlCurrency } from '@/Utilities/Utilities.js'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import MaterialPicker from '@/Components/Materials/MaterialPicker.vue'
import FreeformMaterialForm from '@/Components/Materials/FreeformMaterialForm.vue'
import { useMaterialLines } from '@/Composables/useMaterialLines.js'

const props = defineProps({
    serviceOrderId: {
        type: Number,
        required: true,
    },
    materials: {
        type: Array,
        default: () => [],
    },
    freeformMaterials: {
        type: Array,
        default: () => [],
    },
    allMaterials: {
        type: Array,
        default: () => [],
    },
    materialsUseAjax: { type: Boolean, default: false },
    categories: {
        type: Array,
        default: () => [],
    },
    usageUnits: {
        type: Array,
        default: () => [],
    },
    isClosed: {
        type: Boolean,
        default: false,
    },
    sentToAdministration: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: null,
    },
    /**
     * Which owner the add / edit / delete calls target. Defaults to the service order; the
     * task modal points it at a task instance so the same widget books against the task.
     */
    endpointBase: {
        type: String,
        default: null,
    },
    /**
     * Set when this widget IS a task instance, so its own lines read as editable while lines
     * from other tasks stay read-only. Null on the order, where every task line is foreign.
     */
    taskInstanceId: {
        type: Number,
        default: null,
    },
})

const {
    base,
    canSeeFinancials,
    canCreate,
    canCreateFreeform,
    isForeign,
    canEditLine,
    canRemoveLine,
    quantityError,
    updateLine,
    removeLine,
} = useMaterialLines({
    serviceOrderId: () => props.serviceOrderId,
    endpointBase: () => props.endpointBase,
    taskInstanceId: () => props.taskInstanceId,
})

const showAddForm = ref(false)
const showFinancial = ref(false)
const showUnforseen = computed(() => props.type === 'installation' || props.type === 'mixed')
const locked = computed(() => props.isClosed || props.sentToAdministration)

function getMaterialIcon(material) {
    return getIconByName(material.category?.icon ?? null)
}

const totalMaterialsCount = computed(() => props.materials.length + props.freeformMaterials.length)
const forseenCount = computed(() =>
    props.materials.filter(m => !m.pivot.unforseen).length +
    props.freeformMaterials.filter(f => !f.unforseen).length
)
const unforseenCount = computed(() =>
    props.materials.filter(m => !!m.pivot.unforseen).length +
    props.freeformMaterials.filter(f => !!f.unforseen).length
)

const subtotal = computed(() =>
    props.materials.reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
const forseenSubtotal = computed(() =>
    props.materials.filter(m => !m.pivot.unforseen).reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
const unforseenSubtotal = computed(() =>
    props.materials.filter(m => !!m.pivot.unforseen).reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
</script>
