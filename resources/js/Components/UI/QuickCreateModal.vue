<template>
    <ModalDialog v-model:open="open" :title="title" max-width-class="sm:max-w-2xl">
        <div v-if="loading" class="py-8 text-center text-sm text-gray-500 dark:text-slate-400">Laden…</div>
        <div v-else class="-mx-4 sm:-mx-6">
            <slot />
        </div>
        <p v-if="failure || error" role="alert" class="mt-3 text-sm text-red-600 dark:text-red-400">
            {{ failure || error }}
        </p>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="saving || loading"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    {{ saving ? 'Bezig…' : submitLabel }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import { createRecord } from '@/Utilities/createRecord'

/**
 * Makes one record on top of the form that turned out to need it, so nothing
 * filled in there is lost. The fields go in the slot; this saves them through
 * the record's own endpoint, puts each refusal under the field it is about and
 * hands back what the server made.
 */
const open = defineModel('open', { type: Boolean, default: false })

const props = defineProps({
    title: { type: String, required: true },
    submitLabel: { type: String, required: true },
    url: { type: String, required: true },
    /** The useForm the slot fills in; refusals land in its errors. */
    form: { type: Object, required: true },
    /** What is sent, when that is more than the form's own data. */
    payload: { type: Function, default: null },
    /** While what the fields need is still being fetched. */
    loading: { type: Boolean, default: false },
    /** A problem from before saving, such as a list that would not load. */
    error: { type: String, default: '' },
})

const emit = defineEmits(['created'])

const saving = ref(false)
const failure = ref('')

watch(open, (isOpen) => {
    if (isOpen) failure.value = ''
})

async function submit() {
    saving.value = true
    failure.value = ''
    props.form.clearErrors()

    const { record, errors, message } = await createRecord(props.url, props.payload?.() ?? props.form.data())

    saving.value = false

    if (!record) {
        props.form.setError(errors)
        failure.value = message
        return
    }

    emit('created', record)
    open.value = false
}
</script>
