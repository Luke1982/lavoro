<template>
    <div>
        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                    <Package class="h-5 w-5 text-white" />
                </div>
                <div class="flex flex-col">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Materialen</h2>
                    <p class="text-xs text-slate-400 dark:text-slate-400">Overzicht van gebruikte materialen voor deze
                        bon.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button v-if="canSeeFinancials" type="button" @click="showFinancial = !showFinancial"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 p-1"
                    v-tooltip="showFinancial ? 'Verberg prijzen' : 'Toon prijzen'">
                    <EuroIcon class="size-4" />
                </button>
                <button v-if="!isClosed" type="button" @click="showAddForm = !showAddForm"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-lavoro-blue hover:bg-lavoro-blue/90 rounded-md transition-colors">
                    <PlusIcon class="size-4" />
                    Materiaal toevoegen
                </button>
            </div>
        </div>

        <!-- Add form -->
        <div v-auto-animate>
            <div v-if="showAddForm && !isClosed"
                class="flex flex-col md:flex-row items-center gap-2 mb-4 p-4 rounded-lavoro-sm dark:bg-slate-800/50 border border-gray-200/70 dark:border-slate-700">
                <div class="flex flex-col flex-grow w-full border-r border-r-gray-200/70 pr-4 mr-4 py-2">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Kies een materiaal</span>
                    <ComboBox :options="comboMaterials" v-model="materialToAdd" />
                </div>
                <div class="flex flex-col w-full md:w-28">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Aantal</span>
                    <TextInput v-model="form.quantity" type="number" placeholder="Aantal" />
                </div>
                <div v-if="showUnforseen" class="flex flex-col items-center gap-1 w-full md:w-auto">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Onvoorzien</span>
                    <SwitchComponent v-model="form.unforseen" />
                </div>
                <button @click="attachMaterial" :disabled="sentToAdministration || !materialToAdd" :class="[
                    'w-full md:w-auto px-4 py-2 rounded-md text-sm font-medium transition-colors mt-4',
                    (sentToAdministration || !materialToAdd)
                        ? 'bg-gray-300 dark:bg-slate-700 text-gray-500 dark:text-slate-400 cursor-not-allowed'
                        : 'bg-lavoro-blue text-white hover:bg-lavoro-blue/90 cursor-pointer'
                ]">
                    Toevoegen
                </button>
            </div>
        </div>

        <!-- Sent-to-admin warning -->
        <div v-if="sentToAdministration"
            class="mb-4 p-3 rounded border border-amber-400 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300 text-sm font-semibold">
            Deze order is naar de administratie verzonden. Materialen kunnen niet meer worden aangepast.
        </div>

        <!-- Table -->
        <div v-if="materials.length > 0" class="border-1 rounded-lavoro-sm border-gray-200/70">
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
                <div v-for="material in materials" :key="material.id"
                    class="grid grid-cols-12 py-3 items-center border-b border-gray-100 dark:border-slate-800 last:border-b-0 px-1">

                    <!-- Icon + name + code -->
                    <div :class="(showFinancial && showUnforseen) ? 'md:col-span-4' : 'md:col-span-5'"
                        class="col-span-11 flex items-center gap-3 pl-3">
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
                        </div>
                    </div>

                    <!-- Quantity -->
                    <div :class="showFinancial ? 'col-span-12 md:col-span-2' : (showUnforseen ? 'col-span-12 md:col-span-5' : 'col-span-12 md:col-span-6')"
                        class="flex items-center mt-2 md:mt-0">
                        <template v-if="!sentToAdministration && !isClosed">
                            <EditableTextField inputType="number" v-model="material.pivot.quantity" class="w-20"
                                :error="quantityErrors[material.pivot.id]"
                                @update="val => { form.quantity = Number(val); updateQuantity(material.pivot.id) }">
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
                    <div v-if="showUnforseen" class="col-span-12 md:col-span-1 flex justify-center mt-2 md:mt-0">
                        <SwitchComponent :model-value="!!material.pivot.unforseen"
                            @update:model-value="val => updateUnforseen(material.pivot.id, val)"
                            v-tooltip="material.pivot.unforseen ? 'Onvoorzien' : 'Voorzien'" />
                    </div>

                    <!-- Price per unit -->
                    <div v-if="showFinancial" class="col-span-6 md:col-span-2 mt-2 md:mt-0 pl-3">
                        <span class="text-sm text-gray-700 dark:text-slate-300">{{ nlCurrency(material.price) }}</span>
                    </div>

                    <!-- Line total -->
                    <div v-if="showFinancial" class="col-span-5 md:col-span-2 mt-2 md:mt-0">
                        <span class="text-sm font-medium text-gray-900 dark:text-slate-100">
                            {{ nlCurrency(Number(material.pivot.quantity) * Number(material.price)) }}
                        </span>
                    </div>

                    <!-- Delete -->
                    <div class="col-span-1 flex justify-end pr-2">
                        <TrashIcon v-if="!sentToAdministration && !isClosed"
                            class="size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer transition-colors"
                            @click="detachMaterial(material.pivot.id)"
                            v-tooltip="'Verwijder dit materiaal van de werkbon'" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div
            class="flex items-center justify-between mt-3 font-semibold rounded-lavoro-sm bg-gray-100/50 py-5 px-3 text-xs text-gray-500 dark:text-slate-400">
            <span v-if="showUnforseen">
                {{ materials.length }} materialen,
                {{materials.filter(m => !m.pivot.unforseen).length}} voorzien en
                {{materials.filter(m => !!m.pivot.unforseen).length}} onvoorzien
            </span>
            <span v-else>{{ materials.length }} materialen</span>
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
import { useForm } from '@inertiajs/vue3'
import { Package, Plus as PlusIcon, Trash2 as TrashIcon, Euro as EuroIcon } from '@lucide/vue'
import { getIconByName } from '@/Utilities/lucideIconMap.js'
import { hasPermission, nlCurrency } from '@/Utilities/Utilities.js'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

const props = defineProps({
    serviceOrderId: {
        type: Number,
        required: true,
    },
    materials: {
        type: Array,
        default: () => [],
    },
    allMaterials: {
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
})

const showAddForm = ref(false)
const showFinancial = ref(false)
const canSeeFinancials = computed(() => hasPermission('serviceorder.see_financials'))
const showUnforseen = computed(() => props.type === 'installation' || props.type === 'mixed')

const materialToAdd = ref(null)

const form = useForm({ quantity: 1, unforseen: false })

const comboMaterials = computed(() => {
    return props.allMaterials.slice().sort((a, b) => a.name.localeCompare(b.name)).map(m => {
        const label = canSeeFinancials.value
            ? `${m.name}, code ${m.code}, voorraad ${m.stock}, prijs € ${m.price}`
            : `${m.name}, code ${m.code}, voorraad ${m.stock}`
        return { id: m.id, name: label }
    })
})

function getMaterialIcon(material) {
    return getIconByName(material.category?.icon ?? null)
}

const subtotal = computed(() =>
    props.materials.reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
const forseenSubtotal = computed(() =>
    props.materials.filter(m => !m.pivot.unforseen).reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
const unforseenSubtotal = computed(() =>
    props.materials.filter(m => !!m.pivot.unforseen).reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)

function attachMaterial() {
    if (!materialToAdd.value || form.quantity <= 0) return
    form.post(`/serviceorders/${props.serviceOrderId}/materials/${materialToAdd.value}`, {
        preserveScroll: true,
        onSuccess: () => { showAddForm.value = false },
    })
}

function detachMaterial(materiableId) {
    form.delete(`/serviceorders/${props.serviceOrderId}/materials/${materiableId}`, {
        preserveScroll: true,
    })
}

const quantityErrors = ref({})

function updateQuantity(materiableId) {
    quantityErrors.value[materiableId] = ''
    form.transform(data => ({ quantity: data.quantity }))
        .put(`/serviceorders/${props.serviceOrderId}/materials/${materiableId}`, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); quantityErrors.value[materiableId] = '' },
            onError: (errors) => { quantityErrors.value[materiableId] = errors.quantity ?? '' },
        })
}

function updateUnforseen(materiableId, value) {
    useForm({ unforseen: value }).put(
        `/serviceorders/${props.serviceOrderId}/materials/${materiableId}`,
        { preserveScroll: true }
    )
}
</script>
