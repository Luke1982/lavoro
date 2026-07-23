<template>
    <div class="flex items-center">
        <Link href="/contacts" class="text-slate-400 text-sm font-medium">Contacten</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-100 font-bold text-sm">{{ contact.full_name }}</span>
    </div>

    <div class="flex flex-col mt-6 mb-4">
        <h1 class="text-2xl font-bold dark:text-slate-100">{{ contact.full_name }}</h1>
        <p class="text-gray-500 dark:text-slate-400 text-sm mt-1">{{ contact.email || 'Geen e-mailadres' }}</p>
    </div>

    <BoxComponent>
        <div class="flex items-center mb-4">
            <UserIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
            <span class="text-md font-bold dark:text-slate-100">Contactgegevens</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <EditableTextField v-model="form.first_name" type="input" label="Voornaam"
                :error="form.errors.first_name" :readonly="!canUpdate"
                @revert="form.clearErrors('first_name')" />
            <EditableTextField v-model="form.last_name" type="input" label="Achternaam"
                :error="form.errors.last_name" :readonly="!canUpdate"
                @revert="form.clearErrors('last_name')" />
            <EditableTextField v-model="form.email" type="input" label="E-mail"
                :error="form.errors.email" :readonly="!canUpdate"
                @revert="form.clearErrors('email')" />
        </div>
    </BoxComponent>

    <BoxComponent class="mt-4">
        <div class="flex items-center mb-4">
            <UsersIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
            <span class="text-md font-bold dark:text-slate-100">Gekoppelde klant</span>
        </div>
        <EditableTextField type="combobox" v-model="form.customer_id"
            :options="customerOptions" :has-external-searching="customersUseAjax"
            :searching="customerSearching" @change="searchCustomers" :readonly="!canUpdate"
            :error="form.errors.customer_id" @revert="form.clearErrors('customer_id')">
            <template #display>
                <Link v-if="linkedCustomer" :href="`/customers/${linkedCustomer.id}`"
                    class="text-indigo-600 hover:underline dark:text-indigo-400 text-sm">
                    {{ linkedCustomer.name }}
                </Link>
                <span v-else class="text-sm text-gray-400 dark:text-slate-500">
                    Niet gekoppeld aan een klant
                </span>
            </template>
        </EditableTextField>
    </BoxComponent>
</template>

<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ChevronRightIcon, UserIcon, UsersIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import { hasPermission } from '@/Utilities/Utilities'
import { useComboSearch } from '@/Composables/useComboSearch'

const props = defineProps({
    contact:          { type: Object, required: true },
    allCustomers:     { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
})

const canUpdate = computed(() => hasPermission('contact.update'))

const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const form = useForm({
    first_name:  props.contact.first_name,
    last_name:   props.contact.last_name,
    email:       props.contact.email ?? '',
    customer_id: props.contact.customers?.[0]?.id ?? null,
})

const linkedCustomer = computed(() =>
    props.contact.customers?.find(customer => customer.id === form.customer_id)
    ?? customerOptions.value.find(customer => customer.id === form.customer_id)
    ?? null,
)

watch(
    [() => form.first_name, () => form.last_name, () => form.email, () => form.customer_id],
    () => {
        form.patch(`/contacts/${props.contact.id}`, { preserveScroll: true })
    },
)
</script>
