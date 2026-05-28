<template>
    <div class="fixed inset-0 z-50 backdrop-blur-lg flex items-start lg:items-center justify-center px-5 lg:px-0 bg-white/60 dark:bg-gray-900/60">
        <div class="w-full lg:w-2/3 xl:w-1/2 bg-white dark:bg-gray-800 rounded-2xl mt-3 lg:mt-0 h-[90vh] lg:h-[96vh] shadow-2xl relative text-gray-900 dark:text-gray-100">
            <div class="flex p-4 border-b-gray-200 dark:border-b-gray-700 border-b-1 items-center">
                <div class="w-10/12 text-gray-600 dark:text-gray-300">
                    <span v-if="!editingExisting">Maak een nieuwe afspraak</span>
                    <span v-else>Wijzig afspraak</span>
                </div>
                <div class="w-2/12 flex justify-end">
                    <XMarkIcon class="h-6 w-6 cursor-pointer text-red-600 dark:text-red-400" @click="$emit('close')" />
                </div>
            </div>
            <div class="overflow-y-scroll h-[77vh]">
                <div class="flex flex-wrap">
                    <div class="w-full lg:w-1/2 flex px-4 py-2">
                        <TextInput v-model="form.start_date" label="Startdatum" type="date" class="w-1/2"
                            :has-error="Boolean(form.errors.start)" :error-message="form.errors.start" />
                        <TextInput v-model="form.start_time" label="Starttijd" type="time" class="w-1/2 ml-2"
                            :has-error="Boolean(form.errors.start)" :error-message="form.errors.start" />
                    </div>
                    <div class="w-full lg:w-1/2 flex px-4 py-2">
                        <TextInput v-model="form.end_date" label="Einddatum" type="date" class="w-1/2"
                            :has-error="Boolean(form.errors.end)" :error-message="form.errors.end" />
                        <TextInput v-model="form.end_time" label="Eindtijd" type="time" class="w-1/2 ml-2"
                            :has-error="Boolean(form.errors.end)" :error-message="form.errors.end" />
                    </div>
                </div>
                <div class="flex flex-wrap">
                    <div class="w-1/2 flex px-4 py-2">
                        <ComboBox v-model="form.event_type_id" :options="eventTypes" label="Type" class="w-full"
                            :initial-id="form.event_type_id"
                            :hasError="Boolean(form.errors.event_type_id)"
                            :errorMessage="form.errors.event_type_id" />
                    </div>
                    <div class="w-1/2 flex px-4 py-2">
                        <ComboBox v-model="form.status" :options="eventStatusses" label="Status" class="w-full"
                            :initial-id="initialStatusId" :emitValue="true"
                            :hasError="Boolean(form.errors.status)"
                            :errorMessage="form.errors.status" />
                    </div>
                </div>
                <div class="w-full px-4 py-2">
                    <TextInput v-model="form.name" label="Titel" type="text" class="w-full" />
                </div>
                <div class="w-full px-4 py-2">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Omschrijving</label>
                    <textarea v-model="form.description" rows="4"
                        class="w-full ring-1 ring-inset ring-gray-300 dark:ring-slate-500 bg-white dark:bg-slate-900 dark:text-white rounded-md p-2 text-sm placeholder-gray-400 dark:placeholder:text-gray-600"
                        placeholder="Voeg een omschrijving toe aan de afspraak"></textarea>
                </div>
                <div class="w-full px-4 py-2">
                    <ComboBox v-model="selectedCustomer" :options="allCustomers" label="Klant" class="w-full"
                        :initial-id="form.customer_id || allCustomers[0]?.id" />
                </div>
                <div class="w-full px-4 py-2">
                    <ComboBox v-model="form.eventable_id" :options="internalServiceOrders" label="Werkbon"
                        class="w-full" :initial-id="form.eventable_id"
                        :hasError="Boolean(form.errors.eventable_id)"
                        :errorMessage="form.errors.eventable_id" />
                </div>
                <div class="w-full px-4 py-2">
                    <ComboBox v-model="form.executing_user_ids" :options="allUsers" label="Uitvoerende gebruikers"
                        class="w-full" :initial-ids="form.executing_user_ids" :multiple="true"
                        :hasError="Boolean(form.errors.executing_user_ids)"
                        :errorMessage="form.errors.executing_user_ids" />
                </div>
            </div>
            <div class="absolute bottom-0 w-full flex justify-end rounded-b-2xl overflow-hidden">
                <button @click="$emit('close')"
                    class="bg-gray-200 hover:bg-gray-300 text-gray-800 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-100 font-semibold py-2 px-4 flex-grow">
                    Annuleren
                </button>
                <button @click="save"
                    class="bg-green-500 hover:bg-green-600 text-white dark:bg-green-600 dark:hover:bg-green-700 font-semibold py-2 px-4 flex-grow">
                    Opslaan
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { formatLocalDateAsISO, localToUtcDatetime, nlTime, hasPermission, nlDate } from '@/Utilities/Utilities'

const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    allServiceOrders: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    initial: { type: Object, required: true },
    editingExisting: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'saved'])
const page = usePage()

const form = useForm({
    id: props.initial.id || '',
    event_type_id: props.initial.event_type_id || props.eventTypes[0]?.id || '',
    name: props.initial.name || '',
    description: props.initial.description || '',
    status: props.initial.status || props.eventStatusses[0]?.name || 'Gepland',
    start_date: formatLocalDateAsISO(props.initial.start),
    end_date: formatLocalDateAsISO(props.initial.end),
    start_time: nlTime(props.initial.start),
    end_time: nlTime(props.initial.end),
    eventable_type: props.initial.eventable_type || '\\App\\Models\\ServiceOrder',
    eventable_id: props.initial.eventable_id || '',
    customer_id: props.initial.customer_id || null,
    executing_user_ids: props.initial.executing_user_ids || [],
})

const initialStatusId = computed(() =>
    props.eventStatusses.find(s => s.name === form.status)?.id || props.eventStatusses[0]?.id || ''
)

const selectedCustomer = ref(
    props.initial.customer_id
    || props.allServiceOrders.find(so => so.id === form.eventable_id)?.customer_id
    || props.allCustomers[0]?.id
    || null
)

const internalServiceOrders = computed(() =>
    props.allServiceOrders.filter(so => so.customer_id === selectedCustomer.value).map(so => ({
        id: so.id,
        name: `Order ${so.id} van ${nlDate(so.created_at)}`,
    }))
)

watch(selectedCustomer, (val, oldVal) => {
    if (val === oldVal) return
    if (!internalServiceOrders.value.some(o => o.id === form.eventable_id)) {
        form.eventable_id = internalServiceOrders.value[0]?.id || ''
    }
})

async function save() {
    await axios.get('sanctum/csrf-cookie')
    const payload = {
        ...form,
        start: localToUtcDatetime(form.start_date, form.start_time).slice(0, 16),
        end: localToUtcDatetime(form.end_date, form.end_time).slice(0, 16),
        executing_user_ids: form.executing_user_ids,
    }
    try {
        if (props.editingExisting && form.id) {
            const r = await axios.put(`/api/events/${form.id}`, payload)
            if (r.status !== 200) throw new Error('bad')
            page.props.flash.success = 'Afspraak succesvol bijgewerkt'
        } else {
            if (!hasPermission('event.create')) return
            const r = await axios.post('/api/events', payload)
            if (r.status !== 201) throw new Error('bad')
            page.props.flash.success = 'Afspraak succesvol opgeslagen'
        }
        emit('saved')
    } catch (e) {
        if (e.response?.status === 422) {
            const errs = e.response.data?.errors || {}
            form.clearErrors()
            Object.keys(errs).forEach(k => form.setError(k, Array.isArray(errs[k]) ? errs[k][0] : String(errs[k])))
        }
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet opslaan'
    }
}

onMounted(() => {
    if (!form.eventable_id && internalServiceOrders.value.length > 0) {
        form.eventable_id = internalServiceOrders.value[0].id
    }
})
</script>
