<template>
    <button ref="buttonRef" type="button" class="flex items-center px-2 hover:bg-gray-50 dark:hover:bg-slate-800"
        :class="{ 'bg-gray-100 dark:bg-slate-800': open }" aria-label="Kies een datum" title="Kies een datum"
        aria-haspopup="dialog" :aria-expanded="open" @click="open = !open">
        <CalendarDaysIcon class="size-4" />
    </button>

    <Teleport to="body">
        <transition enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75" leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95">
            <div v-if="open" ref="panelRef" :style="style" role="dialog" aria-label="Kies een datum"
                class="rounded-md bg-white dark:bg-slate-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 p-3 text-gray-900 dark:text-slate-100">
                <MiniCalendar v-bind="$props" @select="pick" />
            </div>
        </transition>
    </Teleport>
</template>

<script setup>
import { ref } from 'vue'
import { CalendarDaysIcon } from '@heroicons/vue/24/outline'
import MiniCalendar from '@/Components/UI/MiniCalendar.vue'
import { usePopover } from '@/Composables/usePopover'

defineProps({
    selectedStart: { type: Date, required: true },
    selectedDays: { type: Number, required: true },
    maxDate: { type: Date, default: null },
})

const emit = defineEmits(['select'])

const buttonRef = ref(null)
const panelRef = ref(null)
const { open, style } = usePopover(buttonRef, panelRef)

function pick(date) {
    open.value = false
    emit('select', date)
}
</script>
