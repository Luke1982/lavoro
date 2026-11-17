<template>
    <div class="w-[22rem] bg-gray-50 p-3 text-gray-900 dark:text-slate-100 dark:bg-slate-800">
        <div v-for="row in rows" :key="row.name" class="flex items-center gap-3 border-b border-gray-200 py-2 last:border-b-0 dark:border-slate-700">
            <div class="flex w-20 shrink-0 items-center gap-2">
                <span class="flex size-7 items-center justify-center rounded-full bg-gray-200 text-[10px] font-semibold dark:bg-slate-700 dark:text-slate-100">{{ row.initials }}</span>
                <span class="truncate text-[11px] font-medium dark:text-slate-200">{{ row.name }}</span>
            </div>
            <div class="relative h-14 flex-1 rounded-md border-l-4 border-sky-500 bg-sky-100 dark:bg-sky-900/30 px-2 py-1.5 text-[11px] leading-tight shadow-sm">
                <div class="font-semibold" :class="row.cancelled ? 'text-gray-500 line-through' : ''">Onderhoud</div>
                <div class="text-gray-600 dark:text-slate-400">Bakker Installatie</div>
                <div class="text-gray-600 dark:text-slate-400">09:00 – 11:00</div>
                <div class="absolute right-1.5 top-1.5 flex items-center gap-1">
                    <span v-for="button in row.buttons" :key="button.key" class="relative flex size-6 items-center justify-center rounded"
                        :class="[button.class, button.hot ? 'scene-hot' : '']">
                        <component :is="button.icon" class="size-4" />
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Clock, X, Ban, RotateCcw, UserPen, Check } from '@lucide/vue'

const props = defineProps({
    variant: { type: String, default: 'default' },
})

const rows = computed(() => {
    const clock = { key: 'clock', icon: Clock, class: 'text-blue-600 dark:text-blue-400 bg-white/80 dark:bg-slate-900/80' }
    const cancel = { key: 'cancel', icon: X, class: 'text-red-600 dark:text-red-400 bg-white/80 dark:bg-slate-900/80' }
    if (props.variant === 'cancelled') {
        return [
            { name: 'Jij', initials: 'JIJ', cancelled: true, buttons: [{ key: 'ban', icon: Ban, class: 'text-gray-500 dark:text-slate-400 bg-white/80 dark:bg-slate-900/80', hot: true }] },
            { name: 'Sanne', initials: 'SB', buttons: [] },
        ]
    }
    if (props.variant === 'others') {
        return [
            { name: 'Jij', initials: 'JIJ', buttons: [clock, cancel] },
            { name: 'Sanne', initials: 'SB', buttons: [{ key: 'pen', icon: UserPen, class: 'text-blue-600 dark:text-blue-400 bg-white/80 dark:bg-slate-900/80', hot: true }] },
        ]
    }
    if (props.variant === 'release') {
        return [
            { name: 'Sanne', initials: 'SB', buttons: [{ key: 'check', icon: Check, class: 'text-green-600 dark:text-green-400' }, { key: 'release', icon: RotateCcw, class: 'text-amber-600 dark:text-amber-400 bg-white/80 dark:bg-slate-900/80', hot: true }] },
            { name: 'Mark', initials: 'MV', cancelled: true, buttons: [{ key: 'ban', icon: Ban, class: 'text-gray-400' }, { key: 'release2', icon: RotateCcw, class: 'text-amber-600 dark:text-amber-400 bg-white/80 dark:bg-slate-900/80' }] },
        ]
    }

    return [
        { name: 'Jij', initials: 'JIJ', buttons: [{ ...clock, hot: true }, { ...cancel, hot: true }] },
        { name: 'Sanne', initials: 'SB', buttons: [] },
    ]
})
</script>

<style scoped>
.scene-hot::after {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 8px;
    box-shadow: 0 0 0 2px var(--color-lavoro-green);
    animation: ring 1.6s ease-out infinite;
}

@keyframes ring {
    from { opacity: 1; transform: scale(0.8); }
    to { opacity: 0; transform: scale(1.5); }
}
</style>
