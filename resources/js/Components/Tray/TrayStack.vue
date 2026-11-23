<template>
    <div class="flex flex-col">
        <div :class="[open ? 'pb-0' : 'pb-2.5', 'group/stack relative transition-[padding] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none']">
            <div v-for="plate in PLATES" :key="plate.inset" aria-hidden="true"
                :class="[plate.inset, open ? plate.folded : plate.hover, 'absolute h-10 rounded-2xl border border-sidebar-border transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none']">
            </div>
            <div draggable="true" @dragstart="startTrayDrag($event, kind, items.map(item => item.id))" @dragend="endTrayDrag"
                title="Sleep om ze allemaal tegelijk te plakken"
                class="relative flex cursor-grab items-center gap-2.5 rounded-2xl bg-[#17223a] py-2 pr-1 pl-1.5 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg active:scale-[0.98] active:cursor-grabbing motion-reduce:transition-none">
                <GripVerticalIcon class="size-4 flex-none text-sidebar-indicator" aria-hidden="true" />
                <span v-if="thumbnails.length" class="relative size-9 flex-none" aria-hidden="true">
                    <img v-for="(thumbnail, index) in thumbnails" :key="thumbnail" :src="thumbnail" alt="" draggable="false"
                        :style="{ transform: `translate(${index * 3}px, ${index * -3}px)`, zIndex: thumbnails.length - index }"
                        class="absolute inset-0 size-8 rounded-lg object-cover ring-2 ring-[#17223a]">
                </span>
                <span v-else :class="[look.tile, 'flex size-9 flex-none items-center justify-center rounded-xl']">
                    <LayersIcon class="size-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="text-[11px] uppercase tracking-wider text-slate-400">
                        Stapel · {{ trayCounted(kind, items.length) }}
                    </div>
                    <div class="truncate text-sm font-semibold text-white">{{ heading }}</div>
                    <div class="truncate text-xs text-slate-400">{{ items.map(item => item.title).join(' · ') }}</div>
                </div>
                <button type="button" @click="pickUpFromTray(kind, items.map(item => item.id))"
                    aria-label="De hele stapel plakken" title="De hele stapel plakken"
                    class="flex size-9 flex-none items-center justify-center rounded-xl text-slate-400 transition hover:bg-sidebar-hover hover:text-white active:scale-90">
                    <ClipboardPasteIcon class="size-5" aria-hidden="true" />
                </button>
                <button type="button" @click="open = !open" :aria-expanded="open"
                    :aria-label="open ? 'Stapel dichtdoen' : 'Stapel openen om er één uit te pakken'"
                    class="flex size-9 flex-none items-center justify-center rounded-xl text-slate-400 transition hover:bg-sidebar-hover hover:text-white active:scale-90">
                    <ChevronDownIcon :class="[open ? 'rotate-180' : '', 'size-5 transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none']"
                        aria-hidden="true" />
                </button>
            </div>
        </div>

        <div :inert="!open"
            :class="[open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]', 'grid transition-[grid-template-rows] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none']">
            <div class="min-h-0 overflow-hidden">
                <div v-auto-animate class="flex flex-col gap-2 border-l-2 border-sidebar-border pt-2 pl-3">
                    <div v-for="(item, index) in items" :key="item.id">
                        <div :style="{ transitionDelay: open ? `${60 + index * 45}ms` : '0ms' }"
                            :class="[open ? 'translate-x-0 opacity-100' : '-translate-x-3 opacity-0', 'transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none']">
                            <TrayItemCard :item="item" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { ChevronDownIcon, ClipboardPasteIcon, GripVerticalIcon, LayersIcon } from '@lucide/vue'
import { startTrayDrag, endTrayDrag, pickUpFromTray, trayCounted } from '@/Composables/useTray.js'
import { trayLook } from '@/Components/Tray/trayLooks.js'
import TrayItemCard from '@/Components/Tray/TrayItemCard.vue'

/**
 * Several bakje items of one kind, lying on one stack.
 *
 * Nothing assumes the whole stack is meant: dragging or pasting the stack takes
 * all of them, and opening it lays every item out to be taken on its own. The stack
 * opens by growing its row from nothing to its full height, so the bakje grows
 * with it instead of jumping; closed, the cards are inert, out of reach of the
 * keyboard as well as out of sight.
 */
const props = defineProps({
    kind: { type: String, required: true },
    items: { type: Array, required: true },
})

const PLATES = [
    { inset: 'inset-x-4 bottom-0 bg-sidebar-card', folded: '-translate-y-2 opacity-0', hover: 'group-hover/stack:translate-y-1' },
    { inset: 'inset-x-2 bottom-1 bg-[#152036]', folded: '-translate-y-1 opacity-0', hover: 'group-hover/stack:translate-y-0.5' },
]

const open = ref(false)

const look = computed(() => trayLook(props.kind))

/** Photos show themselves on the stack, the top three fanned out. */
const thumbnails = computed(() => props.items.map(item => item.thumbnail).filter(Boolean).slice(0, 3))

const heading = computed(() => {
    const customers = new Set(props.items.map(item => item.customer_name).filter(Boolean))
    if (customers.size === 0) return trayCounted(props.kind, props.items.length)
    return customers.size === 1 ? [...customers][0] : 'Verschillende klanten'
})
</script>
