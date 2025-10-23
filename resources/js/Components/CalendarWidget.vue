<template>
    <div :class="{ 'relative': modalOpen }" v-auto-animate>
        <FullCalendar :options="calendarOptions" ref="calendar">
            <template #eventContent="{ event }">
                <div class="flex flex-col relative">
                    <div class="pr-10 flex flex-col">
                        <span class="text-sm font-semibold">{{ event.title }}</span>
                        <span class="text-xs">{{ nlDate(event.start) }} {{ nlTime(event.start) }}</span>
                    </div>
                    <div class="my-2" v-if="event.extendedProps.eventable_id">
                        <Link :href="`/serviceorders/${event.extendedProps.eventable_id}`"
                            class="border rounded-md p-2 my-2 bg-amber-600 md:border-none md:p-0 md:bg-transparent text-xs underline">
                        Werkbon {{ event.extendedProps.eventable_id }}
                        </Link>&nbsp;bij&nbsp;
                        <component :is="hasPermission('customer.read') ? Link : 'span'"
                            :href="`/customers/${getCustomerById(event.extendedProps.customer_id).id}`"
                            :class="['text-xs', hasPermission('customer.read') ? 'underline' : '']">
                            {{ getCustomerById(event.extendedProps.customer_id).name }}
                        </component>
                        <div v-if="event.extendedProps.executing_users?.length > 0"
                            class="mt-3 flex flex-wrap gap-2 ml-1">
                            <div v-for="user in event.extendedProps.executing_users" :key="user.id"
                                class="inline-flex items-center gap-1">
                                <img v-if="user.avatar" :src="user.avatar" :alt="user.name"
                                    class="h-5 w-5 rounded-full ring-1 ring-gray-300 object-cover" />
                                <span v-else
                                    class="h-5 w-5 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[10px] font-medium ring-1 ring-gray-300">{{
                                        initials(user.name) }}</span>
                                <span class="text-[11px] leading-none text-white">{{ user.name }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col absolute top-0 right-0 rounded-bl-md p-1 bg-white">
                        <TrashIcon @click.stop="deleteEvent(event.id)" v-tooltip="'Verwijder afspraak'"
                            class="size-6 text-red-500 cursor-pointer" v-if="hasPermission('event.delete')" />
                        <ClockIcon @click.stop="updateStatus(event.id, 'Afgerond')" v-tooltip="'Rond afspraak af'"
                            class="size-6 text-blue-500 cursor-pointer pt-1"
                            v-if="event.extendedProps.status !== 'Afgerond'" />
                        <CheckIcon @click.stop="updateStatus(event.id, 'Gepland')" v-tooltip="'Markeer als \'Gepland\''"
                            class="size-6 text-green-500 cursor-pointer pt-1" v-else />
                    </div>
                </div>
            </template>
        </FullCalendar>

        <div v-if="modalOpen"
            class="absolute top-0 left-0 w-full h-full z-10 backdrop-blur-lg flex items-start lg:items-center justify-center px-5 lg:px-0 bg-white/60 dark:bg-gray-900/60">
            <div
                class="w-full lg:w-2/3 xl:w-1/2 bg-white dark:bg-gray-800 rounded-2xl mt-3 lg:mt-0 h-[90vh] lg:h-[96vh] shadow-2xl relative text-gray-900 dark:text-gray-100">
                <div class="flex p-4 border-b-gray-200 dark:border-b-gray-700 border-b-1 items-center">
                    <div class="w-10/12 text-gray-600 dark:text-gray-300">
                        <span v-if="!editingExistingEvent">Maak een nieuwe afspraak</span>
                        <span v-else>Wijzig afspraak</span>
                    </div>
                    <div class="w-2/12 flex justify-end">
                        <XMarkIcon class="h-6 w-6 cursor-pointer text-red-600 dark:text-red-400"
                            @click="modalOpen = false" />
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
                                :initial-id="eventTypes[0]?.id" />
                        </div>
                        <div class="w-1/2 flex px-4 py-2">
                            <ComboBox v-model="form.status" :options="eventStatusses" label="Status" class="w-full"
                                :initial-id="getInitialEventStatusId()" :emitValue="true" />
                        </div>
                    </div>
                    <div class="w-full px-4 py-2">
                        <TextInput v-model="form.name" label="Titel" type="text" class="w-full" />
                    </div>
                    <div class="w-full px-4 py-2">
                        <label
                            class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Omschrijving</label>
                        <textarea v-model="form.description" label="Omschrijving" type="textarea"
                            class="w-full ring-1 ring-inset ring-gray-300 dark:ring-slate-500 bg-white dark:bg-slate-900 dark:text-white rounded-md p-2 text-sm placeholder-gray-400 dark:placeholder:text-gray-600"
                            rows="4" placeholder="Voeg een omschrijving toe aan de afspraak"></textarea>
                    </div>
                    <div class="w-full px-4 py-2">
                        <ComboBox v-model="selectedCustomer" :options="allCustomers" label="Klant" class="w-full"
                            :initial-id="allCustomers[0]?.id" />
                    </div>
                    <div class="w-full px-4 py-2">
                        <ComboBox v-model="form.eventable_id" :options="internalServiceOrders" label="Werkbon"
                            class="w-full" :initial-id="form.eventable_id" />
                    </div>
                    <div class="w-full px-4 py-2">
                        <ComboBox v-model="form.executing_user_ids" :options="allUsers" label="Uitvoerende gebruikers"
                            class="w-full" :initial-ids="form.executing_user_ids" :multiple="true" />
                    </div>
                </div>
                <div class="absolute bottom-0 w-full flex justify-end rounded-b-2xl overflow-hidden">
                    <button @click="modalOpen = false; editingExistingEvent = false; form.reset()"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-800 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-100 font-semibold py-2 px-4 flex-grow">
                        Annuleren
                    </button>
                    <button @click="saveEvent"
                        class="bg-green-500 hover:bg-green-600 text-white dark:bg-green-600 dark:hover:bg-green-700 font-semibold py-2 px-4 flex-grow">
                        Opslaan
                    </button>
                </div>
            </div>
        </div>

        <div class="fixed lg:hidden right-3 bottom-3" v-if="!readOnly && hasPermission('event.create')">
            <button @click="modalOpen = true"
                class="bg-blue-600 hover:bg-blue-700 text-white rounded-full p-4 shadow-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
            </button>
        </div>
    </div>
</template>
<script setup>
import FullCalendar from '@fullcalendar/vue3'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import nlLocale from '@fullcalendar/core/locales/nl'
import timeGridPlugin from '@fullcalendar/timegrid'
import { ref, watch, onMounted } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { CheckIcon, ClockIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import { formatLocalDateAsISO, hasPermission, nlDate, nlTime, initials } from '@/Utilities/Utilities'
import ComboBox from '@/Components/UI/ComboBox.vue'

const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    height: { type: String, default: '60vh' },
    readOnly: { type: Boolean, default: false },
})

const page = usePage()

const calendar = ref(null)
const modalOpen = ref(false)
const editingExistingEvent = ref(false)
const selectedCustomer = ref(props.allCustomers[0]?.id || null)

const form = useForm({
    event_type_id: props.eventTypes[0]?.id || '',
    name: '',
    start: '',
    start_date: '',
    start_time: '',
    end: '',
    end_date: '',
    end_time: '',
    eventable_type: '\\App\\Models\\ServiceOrder',
    eventable_id: '',
    id: '',
    description: '',
    status: props.eventStatusses[0]?.name,
    executing_user_ids: props.allUsers.length ? [props.allUsers[0].id] : [],
})

const getCustomerById = (id) => props.allCustomers.find(c => c.id === id) || { id: null, name: 'Onbekende klant' }
const getInternalServiceOrders = () => {
    return props.allServiceOrders.filter(order => order.customer_id === selectedCustomer.value).map(order => ({
        id: order.id,
        name: `Order ${order.id} van ${nlDate(order.created_at)}`,
    }))
}
const internalServiceOrders = ref(getInternalServiceOrders())

const getInitialEventStatusId = () => {
    return editingExistingEvent.value ? (props.eventStatusses.find(status => status.name === form.status)?.id || '') : (props.eventStatusses[0]?.id || '')
}

watch(selectedCustomer, () => {
    internalServiceOrders.value = getInternalServiceOrders()
    if (modalOpen.value) {
        form.eventable_id = internalServiceOrders.value.length > 0 ? internalServiceOrders.value[0].id : ''
    }
})

onMounted(() => {
    form.eventable_id = internalServiceOrders.value.length > 0 ? internalServiceOrders.value[0].id : ''
})

const getView = () => {
    return window.innerWidth < 1366 ? 'listWeek' : 'timeGridWeek'
}

const getHeaderToolbar = () => {
    return {
        left: 'prev,next today',
        center: 'title',
        right: window.innerWidth < 1366 ? '' : 'timeGridWeek,timeGridDay'
    }
}

const finalizeSaveOrUpdate = () => {
    modalOpen.value = false
    form.reset()
    form.clearErrors()
    form.event_type_id = props.eventTypes[0]?.id || ''
    form.eventable_id = internalServiceOrders.value.length > 0 ? internalServiceOrders.value[0].id : ''
    selectedCustomer.value = props.allCustomers[0]?.id || null
    calendar.value?.getApi()?.refetchEvents()
}

const saveEvent = async () => {
    await axios.get('sanctum/csrf-cookie')
    let ok = false
    if (editingExistingEvent.value) {
        ok = await updateEvent()
    } else {
        ok = await createEvent()
    }
    if (ok) {
        finalizeSaveOrUpdate()
    }
}

const createEvent = async () => {
    if (!hasPermission('event.create')) { return false }
    try {
        const response = await axios.post('/api/events', {
            ...form,
            start: form.start_date + ' ' + form.start_time,
            end: form.end_date + ' ' + form.end_time,
            executing_user_ids: form.executing_user_ids,
        })
        if (response.status !== 201) {
            page.props.flash.error = 'Kon de afspraak niet opslaan'
            console.error('Error saving event:', response.data)
            return false
        }
        page.props.flash.success = 'Afspraak succesvol opgeslagen'
        return true
    } catch (error) {
        if (error.response?.status === 422) {
            const errs = error.response.data?.errors || {}
            form.clearErrors()
            Object.keys(errs).forEach(k => form.setError(k, Array.isArray(errs[k]) ? errs[k][0] : String(errs[k])))
        }
        page.props.flash.error = error.response?.data?.message || 'Validatie mislukt bij het opslaan van de afspraak'
        return false
    }
}

const updateEvent = async () => {
    try {
        const response = await axios.put(`/api/events/${form.id}`, {
            ...form,
            start: form.start_date + ' ' + form.start_time,
            end: form.end_date + ' ' + form.end_time,
            executing_user_ids: form.executing_user_ids,
        })
        if (response.status !== 200) {
            page.props.flash.error = 'Kon de afspraak niet bijwerken'
            console.error('Error updating event:', response.data)
            return false
        }
        page.props.flash.success = 'Afspraak succesvol bijgewerkt'
        editingExistingEvent.value = false
        return true
    } catch (error) {
        if (error.response?.status === 422) {
            const errs = error.response.data?.errors || {}
            form.clearErrors()
            Object.keys(errs).forEach(k => form.setError(k, Array.isArray(errs[k]) ? errs[k][0] : String(errs[k])))
        }
        page.props.flash.error = error.response?.data?.message || 'Validatie mislukt bij het bijwerken van de afspraak'
        return false
    }
}

const onSelect = (selectInfo) => {
    if (!hasPermission('event.create')) {
        return
    }
    modalOpen.value = true
    form.start_date = formatLocalDateAsISO(selectInfo.start)
    form.end_date = formatLocalDateAsISO(selectInfo.end)
    form.start_time = nlTime(selectInfo.start)
    form.end_time = nlTime(selectInfo.end)
}

const getEvents = async (fetchInfo, successCallback, failureCallback) => {
    await axios.get('sanctum/csrf-cookie')
    const startParam = `${formatLocalDateAsISO(fetchInfo.start)} ${nlTime(fetchInfo.start)}`
    const endParam = `${formatLocalDateAsISO(fetchInfo.end)} ${nlTime(fetchInfo.end)}`
    const response = await axios.get(`/api/events?start=${encodeURIComponent(startParam)}&end=${encodeURIComponent(endParam)}`)

    if (response.status !== 200) {
        failureCallback('There was an error while fetching events')
        return
    }
    const events = response.data.map(event => ({
        id: event.id,
        title: event.event_type.name,
        start: event.start,
        end: event.end,
        color: event.event_type.color,
        extendedProps: {
            id: event.id,
            name: event.name,
            event_type_id: event.event_type.id,
            eventable_id: event.service_orders[0]?.id,
            eventable_type: '\\App\\Models\\ServiceOrder',
            description: event.description ?? '',
            customer_id: event.service_orders[0]?.customer_id,
            status: event.status,
            executing_user_ids: event.executing_users?.map(u => u.id) || [],
            executing_users: event.executing_users || [],
        },
    }))
    successCallback(events)
}

const updateTimes = async (event) => {
    await axios.get('sanctum/csrf-cookie')
    const startParam = `${formatLocalDateAsISO(event.start)} ${nlTime(event.start)}`
    const endParam = `${formatLocalDateAsISO(event.end)} ${nlTime(event.end)}`
    const response = await axios.put(`/api/events/${event.id}`, {
        start: startParam,
        end: endParam,
    })
    if (response.status !== 200) {
        console.error('Error updating event times:', response.data)
        page.props.flash.error = 'Kon de tijden van de afspraak niet bijwerken'
        return
    }
    page.props.flash.success = 'Tijden van de afspraak succesvol bijgewerkt'
}

const updateStatus = async (eventId, newStatus) => {
    await axios.get('sanctum/csrf-cookie')
    const response = await axios.put(`/api/events/${eventId}`, {
        status: newStatus,
    })
    if (response.status !== 200) {
        console.error('Error updating event status:', response.data)
        page.props.flash.error = 'Kon de status van de afspraak niet bijwerken'
        return
    }
    page.props.flash.success = 'Status van de afspraak succesvol bijgewerkt'
    calendar.value?.getApi()?.refetchEvents()
}

const onDrop = async dropInfo => {
    const { event } = dropInfo
    await updateTimes(event)
}

const onEventClick = (clickInfo) => {
    if (props.readOnly || !hasPermission('event.update')) {
        return
    }
    const event = clickInfo.event
    editingExistingEvent.value = true
    form.id = event.extendedProps.id
    form.name = event.extendedProps.name
    form.description = event.extendedProps.description
    form.start = event.start
    form.end = event.end
    form.start_date = formatLocalDateAsISO(event.start)
    form.end_date = formatLocalDateAsISO(event.end)
    form.start_time = nlTime(event.start)
    form.end_time = nlTime(event.end)
    form.event_type_id = event.extendedProps.event_type_id
    form.eventable_id = event.extendedProps.eventable_id || ''
    form.status = event.extendedProps.status || ''
    form.executing_user_ids = event.extendedProps.executing_user_ids || []
    const serviceOrder = props.allServiceOrders.find(order => order.id === form.eventable_id)
    selectedCustomer.value = serviceOrder ? serviceOrder.customer_id : null
    form.eventable_id = event.extendedProps.eventable_id || ''
    modalOpen.value = true
}

const deleteEvent = async (eventId) => {
    if (!hasPermission('event.delete')) {
        return
    }
    if (!confirm('Weet je zeker dat je deze afspraak wilt verwijderen?')) {
        return
    }
    await axios.get('sanctum/csrf-cookie')
    try {
        const response = await axios.delete(`/api/events/${eventId}`)
        if (response.status === 204) {
            page.props.flash.success = 'Afspraak succesvol verwijderd'
            calendar.value?.getApi()?.refetchEvents()
        } else {
            page.props.flash.error = 'Kon de afspraak niet verwijderen'
        }
    } catch (error) {
        console.error('Error deleting event:', error)
        page.props.flash.error = 'Er is een fout opgetreden bij het verwijderen van de afspraak'
    }
}

const calendarOptions = ref({
    plugins: [timeGridPlugin, interactionPlugin, listPlugin],
    initialView: getView(),
    events: getEvents,
    editable: hasPermission('event.update') && !props.readOnly,
    headerToolbar: getHeaderToolbar(),
    nowIndicator: true,
    selectable: !props.readOnly,
    select: onSelect,
    weekNumbers: true,
    eventDrop: onDrop,
    eventResize: onDrop,
    eventClick: onEventClick,
    height: props.height,
    locale: nlLocale,
    businessHours: {
        daysOfWeek: [1, 2, 3, 4, 5, 6, 0],
        startTime: '08:00',
        endTime: '18:00',
    },
    eventMaxStack: 2,
    dayMaxEventRows: 2,
})
</script>
<style scoped>
@media screen and (max-width: 1024px) {
    .fc {
        height: 92vh !important;
    }
}
</style>
<style>
@media screen and (max-width: 1024px) {
    .fc-header-toolbar {
        flex-direction: column !important;
        display: flex !important;
        gap: 0.5rem;
    }

    .fc-header-toolbar .fc-toolbar-chunk {
        width: 100%;
        display: flex;
        justify-content: space-between;
    }

    .fc-header-toolbar .fc-toolbar-chunk .fc-toolbar-title {
        font-size: 1.25rem;
        width: 100%;
        text-align: center;
    }
}

@media (prefers-color-scheme: dark) {
    .fc-theme-standard .fc-list-day-cushion {
        background-color: #1e293b;
    }

    .fc .fc-list-event:hover td {
        background-color: #334155;
    }
}
</style>