<template>
    <div :class="{ 'relative': modalOpen, 'p-3': true }" v-auto-animate>
        <FullCalendar :options="calendarOptions" ref="calendar">
        </FullCalendar>
        <div v-if="modalOpen"
            class="absolute top-0 left-0 w-full h-full z-10 backdrop-blur-lg flex items-center justify-center">
            <div class="w-1/2 bg-white rounded-2xl h-[96vh] shadow-2xl overflow-y-scroll relative">
                <div class="flex p-4 border-b-gray-200 border-b-1 items-center">
                    <div class="w-10/12 text-gray-600">Maak een nieuwe afspraak</div>
                    <div class="w-2/12 flex justify-end">
                        <XMarkIcon class="h-6 w-6 cursor-pointer text-red-600" @click="modalOpen = false" />
                    </div>
                </div>
                <div class="flex flex-wrap">
                    <div class="w-1/2 flex p-4">
                        <TextInput v-model="form.start_date" label="Startdatum" type="date" class="w-1/2" />
                        <TextInput v-model="form.start_time" label="Starttijd" type="time" class="w-1/2 ml-2" />
                    </div>
                    <div class="w-1/2 flex p-4">
                        <TextInput v-model="form.end_date" label="Einddatum" type="date" class="w-1/2" />
                        <TextInput v-model="form.end_time" label="Eindtijd" type="time" class="w-1/2 ml-2" />
                    </div>
                </div>
                <div class="flex flex-wrap">
                    <div class="w-1/2 flex p-4">
                        <ComboBox v-model="form.event_type_id" :options="eventTypes" label="Type" class="w-full"
                            :initial-id="eventTypes[0].id" />
                    </div>
                    <div class="w-1/2 flex p-4">
                        <TextInput v-model="form.name" label="Titel" type="text" class="w-full" />
                    </div>
                </div>
                <div class="w-full p-4">
                    <label class="block text-sm font-medium leading-6 text-gray-900">Omschrijving</label>
                    <textarea v-model="form.description" label="Omschrijving" type="textarea"
                        class="w-full ring-1 ring-inset ring-gray-300 rounded-md p-2 text-sm" rows="4"
                        placeholder="Voeg een omschrijving toe aan de afspraak"></textarea>
                </div>
                <div class="w-full p-4">
                    <ComboBox v-model="selectedCustomer" :options="allCustomers" label="Klant" class="w-full"
                        :initial-id="allCustomers[0].id" />
                </div>
                <div class="w-full p-4">
                    <ComboBox v-model="form.eventable_id" :options="internalServiceOrders" label="Werkbon"
                        class="w-full" :initial-id="form.eventable_id" />
                </div>
                <div class="absolute bottom-0 w-full flex justify-end ">
                    <button @click="modalOpen = false"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 flex-grow">
                        Annuleren
                    </button>
                    <button @click="saveEvent"
                        class="bg-green-500 hover:bg-green-600 text-white font-semibold py-2 px-4 flex-grow">
                        Opslaan
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import FullCalendar from '@fullcalendar/vue3'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import nlLocale from '@fullcalendar/core/locales/nl'
import timeGridPlugin from '@fullcalendar/timegrid'
import { onMounted, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import { formatLocalDateAsISO, nlDate, nlTime } from '@/Utilities/Utilities'
import ComboBox from '@/Components/UI/ComboBox.vue'

const { eventTypes, allCustomers, allServiceOrders } = defineProps({
    eventTypes: {
        type: Array,
        required: true
    },
    allCustomers: {
        type: Array,
        required: true
    },
    allServiceOrders: {
        type: Array,
        required: true
    }
})

const form = useForm({
    event_type_id: eventTypes[0].id || '',
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
    users: [],
    materials: [],
    status: 'Gepland',
})

const page = usePage()

const calendar = ref(null)
const modalOpen = ref(false)
const selectedCustomer = ref(allCustomers[0].id || null)

const getInternalServiceOrders = () => {
    return allServiceOrders.filter(order => order.customer_id === selectedCustomer.value).map(order => ({
        id: order.id,
        name: `Order ${order.id} van ${nlDate(order.created_at)}`,
    }))
}
const internalServiceOrders = ref(getInternalServiceOrders())

watch(selectedCustomer, () => {
    internalServiceOrders.value = getInternalServiceOrders()
    form.eventable_id = internalServiceOrders.value.length > 0 ? internalServiceOrders.value[0].id : ''
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
    form.event_type_id = eventTypes[0].id || ''
    form.eventable_id = internalServiceOrders.value.length > 0 ? internalServiceOrders.value[0].id : ''
    calendar.value.getApi().refetchEvents()
}

const saveEvent = async () => {
    await axios.get('sanctum/csrf-cookie')
    const response = await axios.post('/api/events', {
        ...form,
        start: form.start_date + ' ' + form.start_time,
        end: form.end_date + ' ' + form.end_time,
    })
    if (response.status !== 201) {
        page.props.flash.error = 'Kon de afspraak niet opslaan'
        console.error('Error saving event:', response.data)
        return
    }
    page.props.flash.success = 'Afspraak succesvol opgeslagen'
    finalizeSaveOrUpdate()
}

const onSelect = (selectInfo) => {
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
    const events = response.data.map(event => {
        return {
            id: event.id,
            title: event.event_type.name,
            start: event.start,
            end: event.end,
            description: event.description ?? '',
            color: event.event_type.color
        }
    })
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
    }
}

const onDrop = async dropInfo => {
    const { event } = dropInfo
    await updateTimes(event)
}

const onResize = async resizeInfo => {
    const { event } = resizeInfo
    await updateTimes(event)
}

const calendarOptions = ref({
    plugins: [timeGridPlugin, interactionPlugin, listPlugin],
    initialView: getView(),
    events: getEvents,
    headerToolbar: getHeaderToolbar(),
    nowIndicator: true,
    selectable: true,
    select: onSelect,
    weekNumbers: true,
    editable: true,
    eventDrop: onDrop,
    eventResize: onResize,
    height: '96vh',
    locale: nlLocale,
    businessHours: {
        daysOfWeek: [1, 2, 3, 4, 5, 6, 0],

        startTime: '08:00',
        endTime: '18:00',
    },
    eventMaxStack: 2,
    dayMaxEventRows: 2,
    eventMouseEnter: (arg) => {
        console.log(arg)
        // const height = arg.el.getBoundingClientRect().height
        // if (height < 50) {
        //     arg.el.querySelector('.overflow-helper').classList.remove('md:hidden')
        //     return
        // }
        // arg.el.querySelector('.tools').classList.remove('hidden')
        // arg.el.querySelector('.tools').classList.add('flex')
    },
    eventMouseLeave: (arg) => {
        console.log(arg)
        // arg.el.querySelector('.overflow-helper').classList.add('md:hidden')
        // arg.el.querySelector('.tools').classList.add('hidden')
        // arg.el.querySelector('.tools').classList.remove('flex')
    }
})
</script>