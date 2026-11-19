<template>
    <DrawerComponent v-model="open" title="Nieuwe offerte"
        subtitle="De offerte krijgt bij het opslaan vanzelf een nummer en is een maand geldig.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <DrawerField label="Titel">
                <TextInput v-model="form.title" type="text" placeholder="Bijvoorbeeld: Airco woonkamer en slaapkamer"
                    :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title" />
            </DrawerField>
            <DrawerField label="Klant">
                <ComboBox :options="customerOptions" v-model="form.customer_id" placeholder="Selecteer klant"
                    :has-external-searching="customersUseAjax" :searching="customersSearching"
                    @change="searchCustomers"
                    :hasError="Boolean(form.errors.customer_id)" :errorMessage="form.errors.customer_id" />
            </DrawerField>
            <DrawerField label="Offertedatum">
                <TextInput v-model="form.quote_date" type="date"
                    :hasError="Boolean(form.errors.quote_date)" :errorMessage="form.errors.quote_date" />
            </DrawerField>
            <DrawerField label="Geldig tot">
                <TextInput v-model="form.valid_until" type="date" placeholder="Een maand na de offertedatum"
                    :hasError="Boolean(form.errors.valid_until)" :errorMessage="form.errors.valid_until" />
            </DrawerField>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import DrawerField from '@/Components/UI/DrawerField.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import { todayIso } from '@/Utilities/Utilities'

/** A new quote. The customer starts empty on purpose, as on a new project. */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
})

const open = defineModel({ type: Boolean, default: false })

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

const form = useForm({
    title: '',
    customer_id: null,
    quote_date: todayIso(),
    valid_until: '',
})

function submit() {
    form.transform(data => ({ ...data, valid_until: data.valid_until || null }))
        .post('/quotes', { onSuccess: () => { open.value = false; form.reset() } })
}
</script>
