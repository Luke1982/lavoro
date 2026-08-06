<template>
    <div class="space-y-1.5">
        <input v-model="label" type="text" placeholder="Naam op de knop"
            class="w-full rounded-md border-slate-300 text-sm">
        <textarea v-model="body" rows="2" placeholder="De vraag zelf"
            class="w-full rounded-md border-slate-300 text-sm" />
        <select v-model="context" class="w-full rounded-md border-slate-300 text-sm">
            <option v-for="page in pages" :key="page.value ?? 'all'" :value="page.value">{{ page.label }}</option>
        </select>
        <div class="flex gap-2">
            <button type="button"
                class="rounded-md bg-lavoro-blue px-2.5 py-1 text-xs font-medium text-white hover:opacity-90"
                @click="$emit('save', { label, question: body, context })">
                Bewaren
            </button>
            <button type="button" class="text-xs text-slate-500 hover:text-slate-700" @click="$emit('cancel')">
                Annuleren
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

/**
 * The three fields a saved question has, written once.
 *
 * Adding a question and editing one are the same form in two places on the list,
 * and having it twice in the panel meant every change to a label or a class had
 * to be made twice — the kind of duplication that stays right for about a week.
 *
 * It holds its own draft and hands the values back on save, so an abandoned edit
 * leaves the list exactly as it was.
 */
const props = defineProps({
    /** What the fields start at: an existing question, or the blanks for a new one. */
    initial: {
        type: Object,
        required: true,
    },
    /** Where a question can be filed, as { value, label }. */
    pages: {
        type: Array,
        required: true,
    },
})

defineEmits(['save', 'cancel'])

const label = ref(props.initial.label ?? '')
const body = ref(props.initial.question ?? '')
const context = ref(props.initial.context ?? null)
</script>
