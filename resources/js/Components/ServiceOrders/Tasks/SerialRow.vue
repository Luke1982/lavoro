<template>
    <div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400 w-5 shrink-0 text-right">{{ number }}.</span>
            <input :value="modelValue" type="text" :placeholder="`Serienummer ${number}`" :disabled="readonly"
                @input="$emit('update:modelValue', $event.target.value)"
                :class="['flex-1 min-w-0 rounded-md border-0 py-1.5 px-3 text-sm ring-1 ring-inset focus:ring-2 focus:ring-inset focus:outline-none bg-white dark:bg-slate-900 text-gray-900 dark:text-white placeholder:text-gray-400', error ? 'ring-red-300 focus:ring-red-500' : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600']" />
            <ScanSerialButton v-if="!readonly" @picked="$emit('update:modelValue', $event)" />
            <button v-if="dirty && !readonly" type="button" :disabled="submitting" @click="$emit('save')"
                v-tooltip="'Serienummer opslaan'"
                class="flex-none p-1.5 rounded-md bg-lavoro-green text-gray-900 hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                <CheckIcon class="w-4 h-4" />
            </button>
            <span v-else-if="saved" class="flex-none p-1.5" v-tooltip="'Opgeslagen als machine'">
                <CheckIcon class="w-4 h-4 text-green-500" />
            </span>
            <span v-else class="flex-none w-7" />
        </div>
        <p v-if="error" class="text-xs text-red-600 mt-1 ml-7">{{ error }}</p>
    </div>
</template>

<script setup>
import { Check as CheckIcon } from '@lucide/vue'
import ScanSerialButton from '@/Components/UI/ScanSerialButton.vue'

/** One machine's serial: typed or scanned, saved on its own. */
defineProps({
    modelValue: { type: String, default: '' },
    number: { type: Number, required: true },
    error: { type: String, default: '' },
    dirty: { type: Boolean, default: false },
    saved: { type: Boolean, default: false },
    submitting: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
})

defineEmits(['update:modelValue', 'save'])
</script>
