<template>
    <div class="flex flex-col gap-2" :class="stacked
        ? ''
        : 'mb-4 p-4 rounded-lavoro-sm bg-slate-50 dark:bg-slate-800/50 border border-gray-200/70 dark:border-slate-700 sm:flex-row sm:items-start'">
        <span v-if="stacked" class="text-xs font-bold text-slate-400 dark:text-slate-300">Vrije materiaalregel</span>
        <div class="flex flex-col w-full" :class="stacked ? '' : 'sm:w-28'">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Aantal</span>
            <TextInput v-model="form.quantity" type="number" placeholder="Aantal" :has-error="!!form.errors.quantity"
                :error-message="form.errors.quantity" class="bg-white" />
        </div>
        <div class="flex flex-col flex-grow">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300 mb-0.5">Omschrijving</span>
            <TextInput v-model="form.description" placeholder="Vrije materiaalregel"
                :has-error="!!form.errors.description" :error-message="form.errors.description" class="bg-white" />
        </div>
        <div v-if="showUnforseen" class="flex items-center gap-1 w-full"
            :class="stacked ? 'justify-between pt-2' : 'flex-col sm:w-auto'">
            <span class="text-xs font-bold text-slate-400 dark:text-slate-300">Onvoorzien</span>
            <SwitchComponent v-model="form.unforseen" :class="stacked ? '' : 'mt-1'" />
        </div>
        <button v-if="stacked" @click="add" :disabled="form.processing"
            class="w-full mt-2 px-4 py-2 rounded-md text-sm font-medium bg-lavoro-blue text-white hover:bg-lavoro-blue/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Vrije regel toevoegen
        </button>
        <button v-else @click="add" :disabled="form.processing" v-tooltip="'Vrije materiaalregel toevoegen'"
            class="flex items-center justify-center w-full sm:w-8.5 h-8.5 mt-4.5 rounded-md bg-lavoro-blue text-white hover:bg-lavoro-blue/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex-none">
            <PlusIcon class="size-5" />
        </button>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { Plus as PlusIcon } from '@lucide/vue'
import TextInput from '@/Components/UI/TextInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

/** A material the catalogue does not have, as a line of its own with its own description. */
const props = defineProps({
    /** Where the line is booked: the werkbon, or a task instance. */
    endpoint: { type: String, required: true },
    showUnforseen: { type: Boolean, default: false },
    /** One field under the other and without a box of its own, for a panel as narrow as a phone. */
    stacked: { type: Boolean, default: false },
})

const emit = defineEmits(['added'])

const form = useForm({ quantity: 1, description: '', unforseen: true })

function add() {
    form.post(props.endpoint + '/freeform-materials', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            emit('added')
        },
    })
}
</script>
