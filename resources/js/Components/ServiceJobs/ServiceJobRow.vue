<template>
    <div class="flex items-center gap-3 py-3 border-b border-gray-100 dark:border-slate-700/60 last:border-0">
        <!-- Arrow indicator (top-level only) -->
        <div v-if="!isChild"
            class="size-7 rounded-full border border-gray-200 dark:border-slate-600 flex items-center justify-center shrink-0">
            <ChevronDownIcon v-if="hasChildren" class="size-5 text-gray-500 dark:text-slate-400" />
            <ChevronRightIcon v-else class="size-5 text-gray-500 dark:text-slate-400" />
        </div>

        <!-- Status icon + title (first column, indented for children) -->
        <div :class="['flex items-center gap-3 flex-1 min-w-0', isChild ? 'pl-4' : '']">
            <div
                :class="[statusBg, statusRing, 'size-10 rounded-full ring-2 flex items-center justify-center shrink-0']">
                <component :is="statusIcon" :class="[statusIconColor, 'size-5']" />
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-semibold text-sm text-lavoro-dark dark:text-white">{{ title }}</span>
                    <BadgeComponent v-if="!isChild && hasChildren" color="blue" :hasDot="false">Hoofdkeuring
                    </BadgeComponent>
                </div>
                <p v-if="subtitle" class="text-xs text-gray-400 dark:text-slate-400">{{ subtitle }}</p>
            </div>
        </div>

        <!-- Volgende keuring -->
        <div class="hidden lg:flex items-center gap-1.5 w-52 shrink-0">
            <CalendarDaysIcon :class="['size-4 shrink-0', isExpired ? 'text-red-500' : 'text-slate-400']" />
            <span v-if="formattedNextServiceDate"
                :class="['text-sm', isExpired ? 'text-red-500 font-medium' : 'text-slate-600 dark:text-slate-300']">
                {{ formattedNextServiceDate }}<span v-if="isExpired" class="ml-1">(verlopen)</span>
            </span>
            <span v-else class="text-sm text-gray-400">—</span>
        </div>

        <!-- Three-dot menu -->
        <Menu as="div" class="relative shrink-0">
            <MenuButton class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-slate-700 cursor-pointer">
                <EllipsisVerticalIcon class="size-5 text-gray-400 dark:text-slate-500" />
            </MenuButton>
            <transition enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75" leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95">
                <MenuItems
                    class="absolute right-0 z-10 mt-1 w-44 rounded-md bg-white dark:bg-slate-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 focus:outline-none">
                    <div class="py-1 text-sm">
                        <MenuItem v-slot="{ active }">
                            <Link :href="`/servicejobs/${servicejob.id}`"
                                :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2 px-4 py-2 text-gray-700 dark:text-slate-300']">
                                <ArrowRightCircleIcon class="size-4 shrink-0" />
                                Uitvoeren
                            </Link>
                        </MenuItem>
                        <MenuItem v-if="hasPermission('servicejob.delete')" v-slot="{ active }">
                            <button @click="deleteServiceJob"
                                :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2 w-full px-4 py-2 text-red-600 dark:text-red-400']">
                                <TrashIcon class="size-4 shrink-0" />
                                Verwijderen
                            </button>
                        </MenuItem>
                    </div>
                </MenuItems>
            </transition>
        </Menu>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue'
import { ShieldCheckIcon, ExclamationTriangleIcon, ClockIcon, CalendarDaysIcon, ArrowRightCircleIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { ChevronDownIcon, ChevronRightIcon, EllipsisVerticalIcon } from '@heroicons/vue/20/solid'
import { Link, useForm } from '@inertiajs/vue3'
import { nlDate, hasPermission } from '@/Utilities/Utilities'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'

const props = defineProps({
    servicejob: { type: Object, required: true },
    hasChildren: { type: Boolean, default: false },
    isChild: { type: Boolean, default: false },
})

const title = computed(() => {
    const asset = props.servicejob.asset
    if (!asset) return props.servicejob.outcome ?? 'Keuring'
    return `${asset.product?.brand?.name ?? ''} ${asset.product?.model ?? ''}`.trim()
})

const subtitle = computed(() => props.servicejob.asset?.serial_number ?? null)

const nextServiceDate = computed(() => props.servicejob.asset?.next_service_date ?? null)

const formattedNextServiceDate = computed(() =>
    nextServiceDate.value ? nlDate(nextServiceDate.value) : null
)

const isExpired = computed(() => {
    if (!nextServiceDate.value) return false
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    return new Date(nextServiceDate.value) < today
})

const outcomeKey = computed(() => (props.servicejob.outcome ?? '').toLowerCase())

const statusIcon = computed(() => {
    if (outcomeKey.value === 'goedkeur' || outcomeKey.value === 'goedkeur na reparatie') return ShieldCheckIcon
    if (outcomeKey.value === 'afkeur') return ExclamationTriangleIcon
    return ClockIcon
})

const statusBg = computed(() => {
    if (outcomeKey.value === 'goedkeur' || outcomeKey.value === 'goedkeur na reparatie') return 'bg-green-100 dark:bg-green-900/30'
    if (outcomeKey.value === 'afkeur') return 'bg-red-100 dark:bg-red-900/30'
    return 'bg-amber-100 dark:bg-amber-900/30'
})

const statusRing = computed(() => {
    if (outcomeKey.value === 'goedkeur' || outcomeKey.value === 'goedkeur na reparatie') return 'ring-green-200 dark:ring-green-800/50'
    if (outcomeKey.value === 'afkeur') return 'ring-red-200 dark:ring-red-800/50'
    return 'ring-amber-200 dark:ring-amber-800/50'
})

const statusIconColor = computed(() => {
    if (outcomeKey.value === 'goedkeur' || outcomeKey.value === 'goedkeur na reparatie') return 'text-green-600 dark:text-green-400'
    if (outcomeKey.value === 'afkeur') return 'text-red-600 dark:text-red-400'
    return 'text-amber-500 dark:text-amber-400'
})

const form = useForm({})

function deleteServiceJob() {
    const message = props.hasChildren
        ? 'Hierdoor worden ook de onderliggende keuringen verwijderd, is dat OK?'
        : 'Weet je zeker dat je deze keuring wilt verwijderen?'
    if (!confirm(message)) return
    form.delete(`/servicejobs/${props.servicejob.id}`, { preserveScroll: true })
}
</script>
