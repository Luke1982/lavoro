<template>
    <QuickCreateModal v-model:open="open" title="Nieuwe klant" submit-label="Klant aanmaken" url="/customers"
        :form="form" @created="emit('created', $event)">
        <CustomerFormFields :form="form" />
    </QuickCreateModal>
</template>

<script setup>
import { watch } from 'vue'
import QuickCreateModal from '@/Components/UI/QuickCreateModal.vue'
import CustomerFormFields from '@/Components/Customers/CustomerFormFields.vue'
import { useCustomerForm } from '@/Composables/useCustomerForm'

/** Makes a customer on top of the form that needed it, starting from the name that was typed. */
const open = defineModel('open', { type: Boolean, default: false })

const props = defineProps({
    /** What was typed in the picker, taken as the name. */
    typed: { type: String, default: '' },
})

const emit = defineEmits(['created'])

const form = useCustomerForm()

watch(open, (isOpen) => {
    if (!isOpen) return

    form.reset()
    form.clearErrors()
    form.name = props.typed
})
</script>
