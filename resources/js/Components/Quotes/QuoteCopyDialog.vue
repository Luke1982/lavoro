<template>
    <ModalDialog :open="open" @update:open="(value) => open = value" :title="asTemplate ? 'Bewaren als sjabloon' : 'Kopie maken'">
        <p class="text-sm text-gray-500 dark:text-slate-400">
            {{ asTemplate
                ? 'Een sjabloon bewaart de regels, de teksten en de afspraken van deze offerte, om er later een nieuwe mee te beginnen.'
                : 'Een nieuwe offerte als concept, met dezelfde regels, teksten en afspraken. Deze offerte blijft zoals hij is.' }}
        </p>
        <div class="mt-4 space-y-4">
            <div>
                <span class="text-xs font-bold text-slate-400">{{ asTemplate ? 'Naam van het sjabloon' : 'Titel' }}</span>
                <TextInput v-model="form.name" :has-error="Boolean(form.errors.name || form.errors.title)"
                    :error-message="form.errors.name ?? form.errors.title" />
            </div>
            <div v-if="!asTemplate">
                <span class="text-xs font-bold text-slate-400">Klant</span>
                <ComboBox :options="customerOptions" v-model="form.customer_id" placeholder="Selecteer klant"
                    :has-external-searching="customersUseAjax" :searching="customersSearching" @change="searchCustomers"
                    :has-error="Boolean(form.errors.customer_id)" :error-message="form.errors.customer_id" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing || !form.name"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60">
                    {{ asTemplate ? 'Bewaren' : 'Kopie maken' }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ComboBox from '@/Components/UI/ComboBox.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useComboSearch } from '@/Composables/useComboSearch'

/**
 * Taking a quote along: as a copy, a new concept for the same customer or
 * another one, or as a template to start later quotes from.
 */
const props = defineProps({
    quote: { type: Object, required: true },
    asTemplate: { type: Boolean, default: false },
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
})

const open = defineModel({ type: Boolean, default: false })

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

const form = useForm({ name: '', customer_id: null })

watch(open, (opened) => {
    if (!opened) return
    form.clearErrors()
    form.name = props.quote.title
    form.customer_id = props.quote.customer_id
})

function submit() {
    const done = { preserveScroll: true, onSuccess: () => { open.value = false } }

    if (props.asTemplate) {
        form.transform(data => ({ name: data.name })).post(`/quotes/${props.quote.id}/template`, done)
    } else {
        form.transform(data => ({ title: data.name, customer_id: data.customer_id })).post(`/quotes/${props.quote.id}/duplicate`, done)
    }
}
</script>
