<template>
    <form class="mt-4 flex items-start gap-2" @submit.prevent="add">
        <div class="flex-1">
            <TextInput v-model="form.name" :placeholder="placeholder" :has-error="Boolean(form.errors.name)" :error-message="form.errors.name" />
        </div>
        <button type="submit" :disabled="!form.name || form.processing"
            class="inline-flex items-center gap-1.5 rounded-lg bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
            <PlusIcon class="size-4" /> Toevoegen
        </button>
    </form>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { PlusIcon } from '@lucide/vue'
import TextInput from '@/Components/UI/TextInput.vue'

/** A field and a button under a list in the quote settings, to add a row to it by name. */
const props = defineProps({
    url: { type: String, required: true },
    placeholder: { type: String, required: true },
})

const form = useForm({ name: '' })

function add() {
    form.post(props.url, { preserveScroll: true, onSuccess: () => form.reset() })
}
</script>
