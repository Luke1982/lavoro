<template>
    <div v-if="box" class="tour-typing pointer-events-none fixed left-0 top-0 z-[10002] flex items-center overflow-hidden"
        :style="box">
        <span class="whitespace-pre">{{ shown }}</span><span class="tour-caret" :class="{ 'tour-caret-still': reduced }" />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useTrackedSpot } from '@/Tours/useTrackedSpot'
import { prefersReducedMotion } from '@/Tours/engine'

/**
 * Text that types itself into a field, to show what a search or a name field is
 * for. It is drawn over the field; the field itself stays empty.
 */
const props = defineProps({
    aid: { type: Object, required: true },
})

const { element, rect } = useTrackedSpot(props.aid.to)
const reduced = prefersReducedMotion()
const shown = ref(reduced ? props.aid.text : '')
let timer = null
let alive = true

const field = computed(() => {
    const spot = element.value
    if (!spot) return null

    return spot.matches('input, textarea') ? spot : spot.querySelector('input, textarea')
})

const box = computed(() => {
    if (!rect.value || !field.value) return null
    const target = field.value.getBoundingClientRect()
    const style = getComputedStyle(field.value)
    const background = style.backgroundColor && !/rgba\(.*,\s*0\)$/.test(style.backgroundColor) && style.backgroundColor !== 'transparent'
        ? style.backgroundColor
        : 'white'

    return {
        transform: 'translate3d(' + (target.left + 1) + 'px, ' + (target.top + 1) + 'px, 0)',
        width: Math.max(0, target.width - 2) + 'px',
        height: Math.max(0, target.height - 2) + 'px',
        paddingLeft: style.paddingLeft,
        fontSize: style.fontSize,
        fontFamily: style.fontFamily,
        color: style.color,
        background,
        borderRadius: style.borderRadius,
        justifyContent: style.textAlign === 'center' ? 'center' : 'flex-start',
    }
})

function type(index) {
    if (!alive) return
    if (index <= props.aid.text.length) {
        shown.value = props.aid.text.slice(0, index)
        timer = setTimeout(() => type(index + 1), 110 + Math.random() * 70)

        return
    }
    timer = setTimeout(() => {
        shown.value = ''
        timer = setTimeout(() => type(1), 600)
    }, 1800)
}

onMounted(() => {
    if (!reduced) timer = setTimeout(() => type(1), 500)
})

onBeforeUnmount(() => {
    alive = false
    clearTimeout(timer)
})
</script>

<style>
.tour-typing {
    box-shadow: 0 0 0 2px rgb(198 255 0 / 0.7);
}

.tour-caret {
    display: inline-block;
    width: 2px;
    height: 1.1em;
    margin-left: 1px;
    background: currentColor;
    animation: tour-caret 1s steps(1) infinite;
}

.tour-caret-still {
    animation: none;
}

@keyframes tour-caret {
    50% { opacity: 0; }
}
</style>
