<template>
    <div draggable="true" @dragstart="startTrayDrag($event, item.kind, [item.id])" @dragend="endTrayDrag"
        class="group flex cursor-grab items-center gap-2.5 rounded-2xl border border-sidebar-border bg-sidebar-card py-2 pr-1 pl-1.5 transition duration-200 ease-out hover:-translate-y-0.5 hover:border-slate-600 hover:shadow-lg active:scale-[0.98] active:cursor-grabbing motion-reduce:transition-none">
        <GripVerticalIcon class="size-4 flex-none text-slate-500 dark:text-slate-400 transition-colors group-hover:text-sidebar-indicator" aria-hidden="true" />
        <span :class="[look.tile, 'flex size-9 flex-none items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-110 motion-reduce:transition-none']">
            <component :is="look.icon" class="size-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-slate-400">
                {{ state.kinds[item.kind]?.one }}
                <Transition enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                    enter-from-class="-translate-x-1 opacity-0" leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
                    leave-to-class="opacity-0">
                    <span v-if="item.pinned" class="inline-flex items-center gap-1 normal-case tracking-normal text-sidebar-indicator">
                        <PinIcon class="size-3" aria-hidden="true" />blijft liggen
                    </span>
                </Transition>
            </div>
            <Link :href="item.url" draggable="false"
                class="block truncate text-sm font-semibold text-white hover:underline">{{ item.title }}</Link>
            <div class="truncate text-xs text-slate-400">{{ details }}</div>
        </div>
        <DropdownMenu placement="top-end" title="Meer acties"
            button-class="flex size-9 items-center justify-center rounded-xl text-slate-400 transition hover:bg-sidebar-hover hover:text-white active:scale-90">
            <template #button>
                <EllipsisVerticalIcon class="size-5" aria-hidden="true" />
                <span class="sr-only">Meer acties</span>
            </template>
            <MenuItem v-slot="{ active }">
                <button type="button" @click="pickUpFromTray(item.kind, [item.id])"
                    :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm text-gray-700 dark:text-slate-200']">
                    <ClipboardPasteIcon class="size-4" aria-hidden="true" />Plakken
                </button>
            </MenuItem>
            <MenuItem v-slot="{ active }">
                <Link :href="item.url"
                    :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200']">
                    <ExternalLinkIcon class="size-4" aria-hidden="true" />Openen
                </Link>
            </MenuItem>
            <MenuItem v-slot="{ active }">
                <button type="button" @click="pinOnTray(item, !item.pinned)"
                    :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm text-gray-700 dark:text-slate-200']">
                    <component :is="item.pinned ? PinOffIcon : PinIcon" class="size-4" aria-hidden="true" />
                    {{ item.pinned ? 'Niet meer laten liggen' : 'Laten liggen na plakken' }}
                </button>
            </MenuItem>
            <MenuItem v-slot="{ active }">
                <button type="button" @click="removeFromTray(item)"
                    :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm text-gray-700 dark:text-slate-200']">
                    <Trash2Icon class="size-4" aria-hidden="true" />Uit het bakje halen
                </button>
            </MenuItem>
        </DropdownMenu>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import {
    ClipboardPasteIcon, EllipsisVerticalIcon, ExternalLinkIcon, GripVerticalIcon, PinIcon, PinOffIcon, Trash2Icon,
} from '@lucide/vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import { useTray, startTrayDrag, endTrayDrag, pickUpFromTray, pinOnTray, removeFromTray } from '@/Composables/useTray.js'
import { trayLook } from '@/Components/Tray/trayLooks.js'

const props = defineProps({
    item: { type: Object, required: true },
})

const { state } = useTray()

const look = computed(() => trayLook(props.item.kind))

const details = computed(() => [
    props.item.subtitle,
    props.item.customer_name,
    props.item.service_order_number ? `op ${props.item.service_order_number}` : null,
].filter(Boolean).join(' · '))
</script>
