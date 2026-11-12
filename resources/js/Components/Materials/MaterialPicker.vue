<template>
    <div>
        <div v-if="showQuickCreate"
            class="flex flex-col gap-2 mb-2 p-4 rounded-lavoro-sm bg-slate-50 dark:bg-slate-800/50 border border-gray-200/70 dark:border-slate-700 relative">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Nieuw materiaal aanmaken</span>
            <div class="flex flex-col items-start gap-2" :class="stacked ? '' : 'md:flex-row'">
                <div class="flex flex-col flex-grow w-full" :class="stacked ? '' : 'sm:w-40'">
                    <TextInput v-model="quickForm.name" placeholder="Naam" :has-error="!!quickForm.errors.name"
                        :error-message="quickForm.errors.name" class="bg-white" />
                </div>
                <div class="flex flex-col" :class="stacked ? 'w-full' : ''">
                    <ComboBox :options="categories" v-model="quickForm.material_category_id" placeholder="Categorie"
                        :has-error="!!quickForm.errors.material_category_id"
                        :error-message="quickForm.errors.material_category_id" />
                </div>
                <div class="flex flex-col" :class="stacked ? 'w-full' : ''">
                    <ComboBox :options="usageUnits" v-model="quickForm.material_usage_unit_id" placeholder="Eenheid"
                        :has-error="!!quickForm.errors.material_usage_unit_id"
                        :error-message="quickForm.errors.material_usage_unit_id" />
                </div>
                <button @click="createMaterial" :disabled="quickForm.processing" :class="[
                    'w-full px-4 py-2 rounded-md text-sm font-medium transition-colors whitespace-nowrap',
                    stacked ? '' : 'md:w-auto',
                    quickForm.processing
                        ? 'bg-gray-300 dark:bg-slate-700 text-gray-500 dark:text-slate-400 cursor-not-allowed'
                        : 'bg-lavoro-blue text-white hover:bg-lavoro-blue/90 cursor-pointer'
                ]">
                    Aanmaken
                </button>
            </div>
            <button class="absolute top-2 right-2" @click="showQuickCreate = false">
                <XCircleIcon class="h-5 w-5 text-gray-400 hover:text-gray-600 dark:hover:text-slate-300" />
            </button>
        </div>
        <div class="flex flex-col items-center gap-2 rounded-lavoro-sm border-gray-200/70 dark:border-slate-700"
            :class="stacked ? '' : 'mb-4 md:flex-row p-0 sm:p-4 sm:border dark:bg-slate-800/50'">
            <div class="flex flex-col flex-grow w-full py-2"
                :class="stacked ? '' : 'sm:border-r border-r-gray-200/70 p-0 sm:pr-4 sm:mr-4'">
                <div class="flex items-center justify-between mb-0.5">
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Kies een materiaal</span>
                </div>
                <div class="flex">
                    <button v-if="canCreateMaterial" type="button" @click="showQuickCreate = !showQuickCreate"
                        class="inline-flex items-center gap-1 text-xs font-semibold bg-lavoro-blue text-white p-0.5 px-2 mr-2 rounded-lavoro-sm"
                        v-tooltip="showQuickCreate ? 'Sluit snel aanmaken' : 'Snel materiaal aanmaken'">
                        <WandIcon class="size-5" />
                    </button>
                    <ComboBox :options="comboMaterials" v-model="materialToAdd" :has-external-searching="materialsUseAjax"
                        :searching="materialSearching" @change="searchMaterials" class="flex-grow" />
                </div>
            </div>
            <div class="flex flex-col w-full" :class="stacked ? '' : 'md:w-28'">
                <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Aantal</span>
                <TextInput v-model="form.quantity" type="number" placeholder="Aantal" />
            </div>
            <div v-if="showUnforseen" class="flex items-center gap-1 w-full"
                :class="stacked ? 'justify-between pt-2' : 'flex-col md:w-auto'">
                <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Onvoorzien</span>
                <SwitchComponent v-model="form.unforseen" />
            </div>
            <button @click="attachMaterial" :disabled="sentToAdministration || !materialToAdd" :class="[
                'w-full px-4 py-2 rounded-md text-sm font-medium transition-colors mt-4',
                stacked ? '' : 'md:w-auto',
                (sentToAdministration || !materialToAdd)
                    ? 'bg-gray-300 dark:bg-slate-700 text-gray-500 dark:text-slate-400 cursor-not-allowed'
                    : 'bg-lavoro-blue text-white hover:bg-lavoro-blue/90 cursor-pointer'
            ]">
                Toevoegen
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { WandIcon, XCircleIcon } from '@lucide/vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import { useMaterialPermissions } from '@/Composables/useMaterialLines.js'

/**
 * Puts a catalogue material on a werkbon or task, with the button that creates a
 * material on the spot when the catalogue does not have it yet.
 */
const props = defineProps({
    /** Where the line is booked: the werkbon, or a task instance. */
    endpoint: { type: String, required: true },
    allMaterials: { type: Array, default: () => [] },
    materialsUseAjax: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    usageUnits: { type: Array, default: () => [] },
    sentToAdministration: { type: Boolean, default: false },
    showUnforseen: { type: Boolean, default: false },
    /** One field under the other and without a box of its own, for a panel as narrow as a phone. */
    stacked: { type: Boolean, default: false },
})

const emit = defineEmits(['added'])

const { canSeeFinancials, canCreateMaterial } = useMaterialPermissions()

const materialToAdd = ref(null)
const form = useForm({ quantity: 1, unforseen: false })

const { options: materialOptions, searching: materialSearching, search: searchMaterials } =
    useComboSearch('materials', props.allMaterials, props.materialsUseAjax)

const comboMaterials = computed(() => {
    return materialOptions.value.slice().sort((a, b) => a.name.localeCompare(b.name)).map(m => {
        const parts = [m.name]
        if (m.code) {
            parts.push('code ' + m.code)
        }
        parts.push('voorraad ' + m.stock)
        if (canSeeFinancials.value) {
            parts.push('prijs € ' + m.price)
        }
        return { id: m.id, name: parts.join(', ') }
    })
})

const showQuickCreate = ref(false)
const page = usePage()
const quickForm = useForm({
    name: '',
    material_category_id: null,
    material_usage_unit_id: null,
})

function createMaterial() {
    quickForm.post('/materials', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = page.props.flash?.extra
            if (created) {
                materialOptions.value = [created, ...materialOptions.value]
                materialToAdd.value = created.id
            }
            showQuickCreate.value = false
            quickForm.reset()
        },
    })
}

function attachMaterial() {
    if (!materialToAdd.value || form.quantity <= 0) return
    form.post(props.endpoint + '/materials/' + materialToAdd.value, {
        preserveScroll: true,
        onSuccess: () => {
            materialToAdd.value = null
            form.reset()
            emit('added')
        },
    })
}
</script>
