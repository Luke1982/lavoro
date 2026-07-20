<template>
    <div data-overlap-band class="absolute rounded-sm" :class="blocksCards ? 'pointer-events-none' : ''"
        :style="hatchStyle" v-tooltip="blocksCards ? null : tooltip"></div>
    <div class="@container absolute pointer-events-none" :style="badgeStyle">
        <!-- A band too narrow for the text keeps only the hatch, unless cards are
             hidden underneath it — that warning has to show at any width. -->
        <div class="absolute inset-0 items-center justify-center"
            :class="isHidden ? 'flex' : 'hidden @min-[70px]:flex'">
            <!-- Never interactive: it floats above the cards and would otherwise
                 swallow clicks meant for the event underneath it. -->
            <span
                class="inline-flex items-center gap-1 rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white shadow-sm">
                <TriangleAlert v-if="isHidden" class="size-3 shrink-0" />
                <CircleAlert v-else class="size-3 shrink-0" />
                <span :class="isHidden ? 'hidden @min-[70px]:inline' : ''">{{ label }}</span>
            </span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { TriangleAlert, CircleAlert } from '@lucide/vue'
import { formatDurationLabel } from '@/Utilities/plannerOverlaps'

defineOptions({ inheritAttrs: false })

const props = defineProps({
    band: { type: Object, required: true },
    dayStartHour: { type: Number, default: 7 },
    dayEndHour: { type: Number, default: 18 },
    eventPaddingY: { type: Number, default: 14 },
})

const totalMin = computed(() => (props.dayEndHour - props.dayStartHour) * 60)

// Cards the lane could not stack are still hidden underneath the band.
const isHidden = computed(() => props.band.hiddenCount > 1)

// The band's own span. A hidden-card count describes a shorter stretch than the
// merged band can cover, so the two never share a label.
const label = computed(() => formatDurationLabel(props.band.durationMin))

const tooltip = computed(() => isHidden.value
    ? `${props.band.hiddenCount} afspraken op exact dezelfde tijd`
    : `${label.value} overlap`
)

// A band drawn over cards would swallow their clicks, so only that one skips
// the hover target.
const blocksCards = computed(() => props.band.coversCards && !props.band.behindCards)

const bounds = computed(() => {
    const startMin = Math.max(0, props.band.startMin)
    const endMin = Math.min(totalMin.value, props.band.endMin)
    return {
        left: (startMin / totalMin.value) * 100 + '%',
        width: ((endMin - startMin) / totalMin.value) * 100 + '%',
        top: props.eventPaddingY + 'px',
        bottom: props.eventPaddingY + 'px',
    }
})

const hatchStyle = computed(() => ({
    ...bounds.value,
    backgroundImage: `repeating-linear-gradient(-45deg, transparent, transparent 4px, color-mix(in srgb, ${props.band.color} 45%, transparent) 4px, color-mix(in srgb, ${props.band.color} 45%, transparent) 9px)`,
    zIndex: props.band.behindCards ? 5 : 7,
}))

const badgeStyle = computed(() => ({ ...bounds.value, zIndex: 8 }))
</script>
