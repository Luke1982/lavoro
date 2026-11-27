<template>
    <DrawerComponent v-model="open" title="Nieuw project" :subtitle="quote
        ? `Van offerte ${quote.number}, voor dezelfde klant, locatie en contactpersoon. De offerte en een werkbon ervan komen in het project.`
        : 'Het project krijgt bij het opslaan vanzelf een nummer.'">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <DrawerField v-if="templateOptions.length" label="Sjabloon" data-tour="project.new.template">
                <ComboBox :options="templateOptions" v-model="form.project_template_id" placeholder="Geen sjabloon"
                    :hasError="Boolean(form.errors.project_template_id)"
                    :errorMessage="form.errors.project_template_id" />
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    Zet de mijlpalen van het sjabloon op het project, gerekend vanaf de startdatum.
                </p>
            </DrawerField>
            <DrawerField label="Titel">
                <TextInput v-model="form.title" type="text"
                    :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title" />
            </DrawerField>
            <DrawerField v-if="quote" label="Klant">
                <p class="py-2 text-sm text-gray-900 dark:text-slate-100">{{ quote.customer.name }}</p>
            </DrawerField>
            <DrawerField v-else label="Klant" data-tour="project.new.customer">
                <ComboBox :options="customerOptions" v-model="form.customer_id" placeholder="Selecteer klant"
                    :has-external-searching="customersUseAjax" :searching="customersSearching"
                    @change="searchCustomers"
                    :hasError="Boolean(form.errors.customer_id)" :errorMessage="form.errors.customer_id" />
            </DrawerField>
            <DrawerField label="Projectleider" data-tour="project.new.manager">
                <ComboBox :options="users" v-model="form.project_manager_id" placeholder="Selecteer projectleider"
                    :hasError="Boolean(form.errors.project_manager_id)"
                    :errorMessage="form.errors.project_manager_id" />
            </DrawerField>
            <DrawerField label="Status" data-tour="project.new.status">
                <ComboBox :options="statuses" v-model="form.status" emit-value
                    :hasError="Boolean(form.errors.status)" :errorMessage="form.errors.status" />
            </DrawerField>
            <DrawerField label="Startdatum" data-tour="project.new.period">
                <TextInput v-model="form.start_date" type="date"
                    :hasError="Boolean(form.errors.start_date)" :errorMessage="form.errors.start_date" />
            </DrawerField>
            <DrawerField label="Einddatum" data-tour="project.new.period">
                <TextInput v-model="form.end_date" type="date"
                    :hasError="Boolean(form.errors.end_date)" :errorMessage="form.errors.end_date" />
            </DrawerField>
            <DrawerField label="Begrote uren" data-tour="project.new.hours">
                <TextInput v-model="form.budget_hours" type="number" placeholder="Optioneel"
                    :hasError="Boolean(form.errors.budget_hours)" :errorMessage="form.errors.budget_hours" />
            </DrawerField>
            <DrawerField label="Omschrijving" tall>
                <textarea v-model="form.description" rows="4" placeholder="Optioneel"
                    class="block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm p-2" />
                <p v-if="form.errors.description" class="text-red-600 dark:text-red-400 text-sm mt-1">
                    {{ form.errors.description }}
                </p>
            </DrawerField>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" data-tour="project.new.save" @click="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import DrawerField from '@/Components/UI/DrawerField.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useComboSearch } from '@/Composables/useComboSearch'

/**
 * A new project. Customer and project leader start empty on purpose: a picker that
 * starts on the first name in the list made projects for the wrong customer the
 * moment someone saved without looking. From an accepted quote the customer is
 * the quote's, and the title starts as the quote's.
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    /** The accepted quote the project is made from: { id, number, title, customer: { name } }. */
    quote: { type: Object, default: null },
})

const open = defineModel({ type: Boolean, default: false })

const form = useForm({
    project_template_id: null,
    title: props.quote?.title ?? '',
    customer_id: null,
    project_manager_id: null,
    status: props.statuses[0]?.name ?? null,
    start_date: '',
    end_date: '',
    budget_hours: '',
    description: '',
})

const templateOptions = computed(() => props.templates.map(t => ({ id: t.id, name: t.name })))

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset()
        form.clearErrors()
    }
})

function submit() {
    form.transform(data => ({
        ...data,
        start_date: data.start_date || null,
        end_date: data.end_date || null,
        budget_hours: data.budget_hours === '' ? null : data.budget_hours,
    })).post(props.quote ? `/quotes/${props.quote.id}/project` : '/projects', {
        onSuccess: () => {
            open.value = false
        },
    })
}
</script>
