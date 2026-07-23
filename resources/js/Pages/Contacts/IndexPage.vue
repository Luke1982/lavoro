<template>
    <IndexHeaderComponent title="Contacten" subtitle="Overzicht van alle contactpersonen"
        search-url="/contacts" search-placeholder="Zoek op naam of e-mail..."
        add-label="Voeg contact toe" @add="showContactDrawer = true"
        :can-add="hasPermission('contact.create')" />

    <PaginationComponent v-if="(contacts.links || []).length" :paginator="contacts"
        :params="{ search: searchParam }" class="border-b border-gray-200 dark:border-slate-700/60" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="contacts.data?.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-4">Naam</div>
                <div class="col-span-4">E-mail</div>
                <div class="col-span-3">Klant</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="contact in contacts.data" :key="contact.id"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <div class="col-span-4">
                    <Link :href="`/contacts/${contact.id}`"
                        class="font-medium hover:underline text-gray-900 dark:text-slate-100">
                        {{ contact.full_name }}
                    </Link>
                </div>
                <div class="col-span-4 text-gray-500 dark:text-slate-400">
                    {{ contact.email || '—' }}
                </div>
                <div class="col-span-3 flex flex-wrap gap-1">
                    <template v-for="customer in contact.customers" :key="customer.id">
                        <Link :href="`/customers/${customer.id}`"
                            class="text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ customer.name }}
                        </Link>
                    </template>
                    <span v-if="!contact.customers?.length" class="text-gray-400">—</span>
                </div>
                <div class="col-span-1 flex justify-end">
                    <div v-if="hasPermission('contact.delete')"
                        class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500"
                            @click="deleteContact(contact.id)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <UserIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen contacten gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <PaginationComponent v-if="(contacts.links || []).length" :paginator="contacts"
        :params="{ search: searchParam }" class="border-t border-gray-200 dark:border-slate-700/60" />

    <DrawerComponent v-model="showContactDrawer" title="Nieuw contact toevoegen"
        subtitle="Vul de gegevens in van het nieuwe contact.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Voornaam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.first_name" type="text"
                        :hasError="Boolean(newContactForm.errors.first_name)"
                        :errorMessage="newContactForm.errors.first_name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Achternaam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.last_name" type="text"
                        :hasError="Boolean(newContactForm.errors.last_name)"
                        :errorMessage="newContactForm.errors.last_name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">E-mail</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.email" type="text"
                        :hasError="Boolean(newContactForm.errors.email)"
                        :errorMessage="newContactForm.errors.email" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Telefoon</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.phone" type="text"
                        :hasError="Boolean(newContactForm.errors.phone)"
                        :errorMessage="newContactForm.errors.phone" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Mobiel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.mobile" type="text"
                        :hasError="Boolean(newContactForm.errors.mobile)"
                        :errorMessage="newContactForm.errors.mobile" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Klant</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="customerOptions" v-model="newContactForm.customer_id"
                        :has-external-searching="customersUseAjax" :searching="customerSearching"
                        @change="searchCustomers" placeholder="Selecteer klant"
                        :hasError="Boolean(newContactForm.errors.customer_id)"
                        :errorMessage="newContactForm.errors.customer_id" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeContactDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewContact" :disabled="newContactForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { UserIcon, TrashIcon } from '@heroicons/vue/24/outline'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import { hasPermission } from '@/Utilities/Utilities'
import { useComboSearch } from '@/Composables/useComboSearch'

const showContactDrawer = ref(false)

const props = defineProps({
    contacts:         { type: Object, required: true },
    allCustomers:     { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    search:           { type: String, default: '' },
})

const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const searchParam = props.search

const newContactForm = useForm({
    first_name:  '',
    last_name:   '',
    email:       '',
    phone:       '',
    mobile:      '',
    customer_id: null,
})

function submitNewContact() {
    newContactForm.post('/contacts', {
        preserveScroll: true,
        onSuccess: () => {
            showContactDrawer.value = false
            newContactForm.reset()
        },
    })
}

function closeContactDrawer() {
    showContactDrawer.value = false
    newContactForm.reset()
    newContactForm.clearErrors()
}

function deleteContact(id) {
    if (!confirm('Weet je zeker dat je dit contact wilt verwijderen?')) return
    useForm({}).delete(`/contacts/${id}`, { preserveScroll: true, preserveState: true })
}
</script>
