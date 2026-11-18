<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="translate-y-full opacity-0"
            enter-to-class="translate-y-0 opacity-100" leave-active-class="transition ease-in duration-150"
            leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-full opacity-0">
            <div v-if="show" ref="bar"
                class="fixed inset-x-3 z-40 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 dark:border-slate-700 bg-gray-100 dark:bg-slate-800 px-4 py-3 text-gray-800 dark:text-slate-200 shadow-lg lg:right-0 lg:rounded-none lg:border-x-0 lg:border-b-0 lg:px-6 lg:py-4"
                :class="collapsed ? 'lg:left-[4.5rem]' : 'lg:left-72'" :style="{ bottom: `${bottom}px` }">
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <slot />
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <slot name="actions" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useMenu } from '@/Composables/useMenu.js'
import { useTabBar } from '@/Composables/useTabBar.js'
import { useBottomBar } from '@/Composables/useBottomBars.js'

/**
 * A bar along the bottom with what moves a screen on: what to do with the ticked
 * rows of a list, or the next step of a wizard. It stays in reach on a long page;
 * the page underneath needs room at its end to scroll its last content out from
 * under it.
 *
 * On a computer it lies along the bottom edge, beside the menu however wide that
 * is. On a phone it floats above the bar at the bottom, which would otherwise lie
 * over it; swiped away, that bar leaves the room to this one. It reports itself
 * as a bottom bar, so the bakje rises above it instead of covering its buttons.
 */
defineProps({
    show: { type: Boolean, default: true },
})

const GAP = 12

const { collapsed } = useMenu()
const { tucked, clearance } = useTabBar()

const bar = ref(null)
useBottomBar(bar)

const bottom = computed(() => {
    if (clearance.value === 0) return 0
    return tucked.value ? GAP : clearance.value + GAP
})
</script>
