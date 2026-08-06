<template>
    <Link v-if="showButton" :href="plannerUrl" class="relative z-10 shrink-0" @click.stop
        :aria-label="`Werkbon ${serviceorder.id} inplannen in de planning`">
        <span v-if="variant === 'button'"
            class="flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800">
            <CalendarDaysIcon class="size-4 shrink-0" />
            Inplannen
        </span>
        <span v-else-if="variant === 'circle'" v-tooltip="'Inplannen in de planning'"
            class="border-1 border-lavoro-darkergray rounded-full p-2 flex text-lavoro-darkerblue">
            <CalendarDaysIcon class="h-5 w-5" />
        </span>
        <CalendarDaysIcon v-else v-tooltip="'Inplannen in de planning'"
            class="size-4.5 cursor-pointer text-lavoro-blue opacity-70 transition hover:opacity-100" />
    </Link>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { CalendarDaysIcon } from '@heroicons/vue/24/outline'
import { hasPermission } from '@/Utilities/Utilities'
import { serviceOrderIsPlannable } from '@/Utilities/serviceOrders'

const props = defineProps({
    serviceorder: { type: Object, required: true },
    variant: { type: String, default: 'icon' },
})

const showButton = computed(() =>
    hasPermission('serviceorder.plan') && serviceOrderIsPlannable(props.serviceorder)
)

const plannerUrl = computed(() => `/planner?highlightserviceorder=${props.serviceorder.id}`)
</script>
