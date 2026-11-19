<template>
    <div>
        <div v-if="showQuickCreate"
            class="flex flex-col gap-2 mb-2 p-4 rounded-lavoro-sm bg-slate-50 dark:bg-slate-800/50 border border-gray-200/70 dark:border-slate-700 relative">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Nieuw materiaal aanmaken</span>
            <div class="flex flex-col items-start gap-2" :class="stacked ? '' : 'md:flex-row'">
                <div class="flex flex-col flex-grow w-full" :class="stacked ? '' : 'sm:w-40'">
                    <TextInput v-model="quickForm.name" placeholder="Naam" :has-error="!!quickForm.errors.name"
                        :error-message="quickForm.errors.name" class="bg-white dark:bg-slate-900" />
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
                :class="stacked ? '' : 'sm:border-r border-r-gray-200/70 dark:border-r-slate-700/70 p-0 sm:pr-4 sm:mr-4'">
                <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Materiaal</span>
                <div class="flex">
                    <button v-if="canCreate && canCreateMaterial" type="button" @click="showQuickCreate = !showQuickCreate"
                        class="inline-flex items-center gap-1 text-xs font-semibold bg-lavoro-blue text-white p-0.5 px-2 mr-2 rounded-lavoro-sm"
                        v-tooltip="showQuickCreate ? 'Sluit snel aanmaken' : 'Snel materiaal aanmaken'">
                        <WandIcon class="size-5" />
                    </button>
                    <ComboBox v-if="canCreate" :options="comboMaterials" v-model="choice"
                        :has-external-searching="materialsUseAjax" :searching="materialSearching"
                        placeholder="Wat heb je gebruikt?"
                        :create-label="canCreateFreeform ? 'Anders, namelijk' : ''"
                        :has-error="!!form.errors.description" :error-message="form.errors.description"
                        @change="searchMaterials" @create="chooseOwnLine" class="flex-grow" />
                    <TextInput v-else v-model="form.description" placeholder="Wat heb je gebruikt?"
                        :has-error="!!form.errors.description" :error-message="form.errors.description"
                        class="flex-grow" />
                </div>
            </div>
            <div class="flex flex-col w-full" :class="stacked ? '' : 'md:w-28'">
                <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Aantal</span>
                <TextInput v-model="form.quantity" type="number" placeholder="Aantal"
                    :has-error="!!form.errors.quantity" :error-message="form.errors.quantity" />
            </div>
            <div v-if="showUnforseen" class="flex items-center gap-1 w-full"
                :class="stacked ? 'justify-between pt-2' : 'flex-col md:w-auto'">
                <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Onvoorzien</span>
                <SwitchComponent v-model="unforseen" />
            </div>
            <button @click="add" :disabled="!canSubmit" :class="[
                'w-full px-4 py-2 rounded-md text-sm font-medium transition-colors mt-4',
                stacked ? '' : 'md:w-auto',
                canSubmit
                    ? 'bg-lavoro-blue text-white hover:bg-lavoro-blue/90 cursor-pointer'
                    : 'bg-gray-300 dark:bg-slate-700 text-gray-500 dark:text-slate-400 cursor-not-allowed'
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
 * Puts what was used on a werkbon or task, as one field: pick it from the catalogue,
 * or, for whoever may book free lines, type what the catalogue does not have and take
 * "Anders, namelijk". Whoever may only do one of the two gets just that one, in the
 * same place. Which kind of line it becomes is this form's business, not the user's.
 */
const props = defineProps({
    /** Where the line is booked: the werkbon, or a task instance. */
    endpoint: { type: String, required: true },
    allMaterials: { type: Array, default: () => [] },
    materialsUseAjax: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    usageUnits: { type: Array, default: () => [] },
    showUnforseen: { type: Boolean, default: false },
    /** One field under the other and without a box of its own, for a panel as narrow as a phone. */
    stacked: { type: Boolean, default: false },
})

const emit = defineEmits(['added'])

const { canSeeFinancials, canCreate, canCreateFreeform, canCreateMaterial } = useMaterialPermissions()

/** The combobox id of a line typed by hand, so it shows as chosen like any catalogue material. */
const OWN_LINE = 'own-line'

const choice = ref(null)
const form = useForm({ quantity: 1, description: '' })

const isOwnLine = computed(() => !canCreate.value || choice.value === OWN_LINE)
const canSubmit = computed(() => !form.processing && (!canCreate.value || !!choice.value))

/**
 * A line typed by hand starts as onvoorzien and a catalogue material as voorzien, as
 * each always did on its own form. Once the switch is touched, it is what was chosen.
 */
const unforseenChoice = ref(null)
const unforseen = computed({
    get: () => unforseenChoice.value ?? isOwnLine.value,
    set: (value) => { unforseenChoice.value = value },
})

const { options: materialOptions, searching: materialSearching, search: searchMaterials } =
    useComboSearch('materials', props.allMaterials, props.materialsUseAjax)

const comboMaterials = computed(() => {
    const catalogue = materialOptions.value.slice().sort((a, b) => a.name.localeCompare(b.name)).map(m => {
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

    return choice.value === OWN_LINE ? [...catalogue, { id: OWN_LINE, name: form.description }] : catalogue
})

function chooseOwnLine(description) {
    form.description = description
    choice.value = OWN_LINE
}

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
                choice.value = created.id
            }
            showQuickCreate.value = false
            quickForm.reset()
        },
    })
}

/** A line typed by hand goes to the free lines with its description; a catalogue material only needs its id. */
function add() {
    const ownLine = isOwnLine.value

    form
        .transform(({ quantity, description }) => ({
            quantity,
            unforseen: unforseen.value,
            ...(ownLine && { description }),
        }))
        .post(props.endpoint + (ownLine ? '/freeform-materials' : '/materials/' + choice.value), {
            preserveScroll: true,
            onSuccess: () => {
                choice.value = null
                unforseenChoice.value = null
                form.reset()
                emit('added')
            },
        })
}
</script>
