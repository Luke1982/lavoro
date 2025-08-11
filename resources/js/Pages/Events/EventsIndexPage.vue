<template>
    <div class="relative">
        <FullCalendar :options="calendarOptions" ref="calendar">
        </FullCalendar>
    </div>
</template>

<script setup>
import FullCalendar from '@fullcalendar/vue3'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import nlLocale from '@fullcalendar/core/locales/nl'
import timeGridPlugin from '@fullcalendar/timegrid'
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const form = useForm({
    type: '',
    start: '',
    end: '',
    eventable_type: '',
    eventable_id: '',
    id: '',
    description: '',
    users: [],
    materials: [],
    status: 'Gepland',
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

const onSelect = (selectInfo) => {
    form.start = selectInfo.start
    form.end = selectInfo.end
}

const getEvents = () => {
    //
}

const updateTimes = async (event) => {
    console.log(event)
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
        const height = arg.el.getBoundingClientRect().height
        if (height < 50) {
            arg.el.querySelector('.overflow-helper').classList.remove('md:hidden')
            return
        }
        arg.el.querySelector('.tools').classList.remove('hidden')
        arg.el.querySelector('.tools').classList.add('flex')
    },
    eventMouseLeave: (arg) => {
        arg.el.querySelector('.overflow-helper').classList.add('md:hidden')
        arg.el.querySelector('.tools').classList.add('hidden')
        arg.el.querySelector('.tools').classList.remove('flex')
    }
})
</script>