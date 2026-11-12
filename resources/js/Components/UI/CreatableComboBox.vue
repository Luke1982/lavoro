<template>
    <ComboBox v-bind="$attrs" v-model="selected" :create-label="canCreate ? createLabel : ''" @create="startCreating" />
    <component :is="modal" v-if="canCreate" v-model:open="creating" :typed="typed" @created="select" />
</template>

<script setup>
import { ref } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { hasPermission } from '@/Utilities/Utilities'

/**
 * A ComboBox, taking everything a ComboBox takes, that can also make the record
 * it does not have. Whoever holds `permission` types the missing record and
 * picks the last row; `modal` opens with what was typed (its `typed` prop) and
 * emits `created` with the option it made. That option goes to the parent,
 * which owns the list, and is selected.
 */
defineOptions({ inheritAttrs: false })

const selected = defineModel({ type: [Number, String], default: null })

const props = defineProps({
    createLabel: { type: String, required: true },
    permission: { type: String, required: true },
    modal: { type: Object, required: true },
})

const emit = defineEmits(['created'])

const canCreate = hasPermission(props.permission)
const creating = ref(false)
const typed = ref('')

function startCreating(text) {
    typed.value = text
    creating.value = true
}

function select(option) {
    emit('created', option)
    selected.value = option.id
}
</script>
