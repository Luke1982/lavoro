<template>
    <BoxComponent padding="p-4 sm:p-5">
        <SectionHeader :icon="ClipboardList" title="Wat moet er gebeuren?" chapter="serviceorders" />

        <div class="flex flex-col gap-2">
            <WorkItemRow v-for="job in jobs" :key="'job-' + job.id" :icon="ClipboardCheck" tone="blue"
                :title="jobTitle(job)" :subtitle="machineLine(job.asset)" :href="'/servicejobs/' + job.id"
                action-label="Uitvoeren" :action-icon="Play" done-label="Uitgevoerd" :done="!!job.completed_on" />

            <WorkItemRow v-for="ticket in tickets" :key="'ticket-' + ticket.id" :icon="Wrench" tone="red"
                :title="'Storing – ' + ticket.subject" :subtitle="ticketLine(ticket)" :href="'/tickets/' + ticket.id"
                action-label="Oplossen" :action-icon="Wrench" done-label="Opgelost"
                :done="ticket.status?.toLowerCase() === 'gesloten'" />

            <TaskInstancesWidget v-if="showTasks" compact :service-order-id="serviceOrderId" :instances="taskInstances"
                :is-closed="isClosed" :type="type" :hidden-count="hiddenTaskCount" />

            <p v-if="isEmpty" class="text-sm text-gray-400 dark:text-slate-500">
                Er staan geen keuringen, storingen of taken op deze werkbon.
            </p>
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed } from 'vue'
import { ClipboardCheck, ClipboardList, Play, Wrench } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import TaskInstancesWidget from '@/Components/ServiceOrders/TaskInstancesWidget.vue'
import WorkItemRow from '@/Components/ServiceOrders/WorkItemRow.vue'
import { hasPermission } from '@/Utilities/Utilities'

/**
 * Alles wat er op deze werkbon gedaan moet worden, in één lijst: de keuringen,
 * de storingen en de taken. Een keuring en een storing hebben hun eigen pagina
 * waar het werk gebeurt; een taak vink je hier af.
 */
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    servicejobs: { type: Array, default: () => [] },
    tickets: { type: Array, default: () => [] },
    taskInstances: { type: Array, default: () => [] },
    hiddenTaskCount: { type: Number, default: 0 },
    isClosed: { type: Boolean, default: false },
    type: { type: String, default: null },
})

const jobs = computed(() => hasPermission('servicejob.read') ? props.servicejobs : [])
const showTasks = computed(() => hasPermission('serviceordertaskinstance.read')
    && (props.taskInstances.length > 0 || props.hiddenTaskCount > 0))

const isEmpty = computed(() => jobs.value.length === 0 && props.tickets.length === 0 && !showTasks.value)

function machineLine(asset) {
    if (!asset) return ''

    const machine = [asset.product?.brand?.name, asset.product?.model].filter(Boolean).join(' ')

    return [machine, asset.serial_number].filter(Boolean).join(' · ')
}

function jobTitle(job) {
    const type = job.asset?.product?.product_type?.name

    return type ? 'Keuring – ' + type : 'Keuring'
}

/** "Buitendeel: Toshiba RAS-10J2AVSG-E1 · T7212881, storingscode E4" */
function ticketLine(ticket) {
    const type = ticket.asset?.product?.product_type?.name

    return [
        [type, machineLine(ticket.asset)].filter(Boolean).join(': '),
        ticket.status_code && 'storingscode ' + ticket.status_code,
    ].filter(Boolean).join(', ')
}
</script>
