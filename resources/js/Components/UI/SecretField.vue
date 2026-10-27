<template>
    <div>
        <TextInput :label="label" v-model="internalValue" type="password" autocomplete="new-password"
            :placeholder="stored ? '••••••••  (opgeslagen)' : ''" :has-error="!!error" :error-message="error || ''" />
        <p v-if="stored && !internalValue" class="mt-1 text-xs text-gray-500">
            Staat opgeslagen. Laat leeg om hem te laten staan.
            <button type="button" @click="$emit('forget')"
                class="text-red-600 hover:underline">Wissen</button>
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'

const props = defineProps({
    label: { type: String, required: true },
    modelValue: { type: String, default: '' },
    stored: { type: Boolean, default: false },
    error: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'forget'])

const internalValue = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
})
</script>
