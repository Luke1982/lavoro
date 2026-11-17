<template>
    <div class="w-[22rem] text-gray-800 dark:text-slate-200">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2 text-[11px] text-gray-400 dark:border-slate-700">
            <span class="size-2 rounded-full bg-red-400" /><span class="size-2 rounded-full bg-amber-400" /><span class="size-2 rounded-full bg-green-400" />
            <span class="ml-2 truncate">Profiel · Rooster</span>
        </div>
        <div class="space-y-3 p-4">
            <div class="rounded-lg p-2 transition" :class="variant === 'recurring' ? 'scene-focus' : 'opacity-60'">
                <p class="text-xs font-semibold">Vaste vrije momenten</p>
                <div class="mt-1.5 rounded border border-gray-200 text-[11px] dark:border-slate-700">
                    <div class="flex justify-between px-2 py-1.5">
                        <span>Vrijdag, 00:00 – 23:59 <span class="text-gray-400">(wekelijks) — parttime</span></span><span class="text-red-400">×</span>
                    </div>
                    <div class="flex justify-between border-t border-gray-100 px-2 py-1.5 dark:border-slate-700" :class="variant === 'recurring' ? 'scene-new' : ''">
                        <span>Woensdag, 13:00 – 17:30 <span class="text-gray-400">(om de week) — papadag</span></span><span class="text-red-400">×</span>
                    </div>
                </div>
            </div>
            <div class="rounded-lg p-2 transition" :class="variant === 'holiday' ? 'scene-focus' : 'opacity-60'">
                <p class="text-xs font-semibold">Vrije dagen</p>
                <div class="mt-1.5 rounded border border-gray-200 text-[11px] dark:border-slate-700">
                    <div class="flex justify-between px-2 py-1.5" :class="variant === 'holiday' ? 'scene-new' : ''">
                        <span>20-07-2026 – 07-08-2026 <span class="text-gray-400">— Vakantie</span></span><span class="text-red-400">×</span>
                    </div>
                </div>
            </div>
            <div>
                <p class="text-[10px] uppercase tracking-wide text-gray-400">In de planner</p>
                <div class="mt-1 grid grid-cols-5 overflow-hidden rounded border border-gray-200 text-[10px] dark:border-slate-700">
                    <div v-for="(day, index) in days" :key="day" class="relative h-12 border-l border-gray-100 first:border-l-0 dark:border-slate-700">
                        <span class="absolute left-1 top-0.5 text-gray-400">{{ day }}</span>
                        <div v-if="blockFor(index)" class="scene-block absolute bottom-1 top-4 overflow-hidden px-1 text-[9px] font-medium text-gray-500"
                            :style="{ left: blockFor(index).left, right: '2px', background: pattern }">{{ blockFor(index).label }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    variant: { type: String, default: 'recurring' },
})

const days = ['MA', 'DI', 'WO', 'DO', 'VR']
const pattern = 'repeating-linear-gradient(135deg, rgb(229 231 235 / 0.9) 0 6px, rgb(243 244 246 / 0.9) 6px 12px)'

function blockFor(index) {
    if (props.variant === 'holiday') return { left: '2px', label: 'Vakantie' }
    if (index === 2) return { left: '50%', label: 'papadag' }
    if (index === 4) return { left: '2px', label: 'parttime' }

    return null
}
</script>

<style scoped>
.scene-focus {
    box-shadow: 0 0 0 2px var(--color-lavoro-blue);
    background: rgb(231 239 254 / 0.5);
}

.scene-new {
    animation: slide-in 4s ease-out infinite;
}

.scene-block {
    transform-origin: 0 50%;
    animation: grow 4s ease-out infinite;
}

@keyframes slide-in {
    0%, 8% { opacity: 0; transform: translateX(-10px); background: rgb(198 255 0 / 0.35); }
    20% { opacity: 1; transform: translateX(0); background: rgb(198 255 0 / 0.35); }
    60%, 100% { background: transparent; }
}

@keyframes grow {
    0%, 24% { transform: scaleX(0); opacity: 0; }
    40%, 100% { transform: scaleX(1); opacity: 1; }
}
</style>
