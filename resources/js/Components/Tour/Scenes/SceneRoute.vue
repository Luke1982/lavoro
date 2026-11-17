<template>
    <div class="w-[22rem] p-4 text-gray-900 dark:text-slate-100">
        <div v-if="variant === 'default'" class="mb-3 flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-200 dark:bg-slate-800 dark:ring-slate-700">
            <div>
                <p class="text-xs font-semibold uppercase">DI 18-11</p>
                <p class="text-[10px] text-gray-400">Jan de Vries</p>
            </div>
            <span class="scene-pill inline-flex items-center gap-1 rounded bg-blue-700 px-1.5 py-0.5 text-[11px] font-medium text-white">
                <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M8.157 2.176a1.5 1.5 0 0 0-1.147 0l-4.084 1.69A1.5 1.5 0 0 0 2 5.25v10.877a1.5 1.5 0 0 0 2.074 1.386l3.51-1.452 4.26 1.762a1.5 1.5 0 0 0 1.146 0l4.083-1.69A1.5 1.5 0 0 0 18 14.75V3.872a1.5 1.5 0 0 0-2.073-1.386l-3.51 1.452-4.26-1.762Z" /></svg>
                Route
            </span>
        </div>
        <div v-else-if="variant === 'pings'" class="mb-3 flex items-center justify-between text-xs font-semibold">
            Monteurlocaties (laatste 8u)
            <span class="text-[10px] font-normal text-gray-400">live</span>
        </div>
        <div v-else class="mb-3 flex items-center justify-between text-xs">
            <span class="inline-flex items-center gap-2 font-semibold">
                <span class="relative inline-block h-4 w-7 rounded-full bg-lavoro-blue"><span class="absolute right-0.5 top-0.5 size-3 rounded-full bg-white" /></span>
                Route plannen
            </span>
            <span class="scene-pill rounded-md bg-lavoro-blue px-2 py-1 text-[11px] font-semibold text-white">Route berekenen</span>
        </div>
        <div class="relative h-40 overflow-hidden rounded-xl bg-[#e8eef3] ring-1 ring-gray-200 dark:ring-slate-700">
            <svg viewBox="0 0 320 160" class="absolute inset-0 h-full w-full">
                <path d="M0 120 C 60 110, 90 140, 160 118 S 260 80, 320 96" fill="none" stroke="white" stroke-width="10" />
                <path d="M120 0 C 130 50, 110 90, 140 160" fill="none" stroke="white" stroke-width="8" />
                <path d="M230 0 C 220 60, 260 100, 250 160" fill="none" stroke="white" stroke-width="6" />
                <path v-if="variant !== 'pings'" class="scene-route" d="M40 128 L 96 72 L 168 104 L 236 44 L 284 112" fill="none" stroke="#2563ff" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" />
                <g v-for="(pin, index) in pins" :key="index" class="scene-pin" :style="{ animationDelay: (index * 0.35) + 's' }">
                    <circle v-if="variant === 'pings'" :cx="pin[0]" :cy="pin[1]" r="16" fill="rgb(37 99 255 / 0.18)" class="scene-pulse" />
                    <circle :cx="pin[0]" :cy="pin[1]" r="9" :fill="variant === 'plan' ? '#2563ff' : '#081020'" stroke="white" stroke-width="2" />
                    <text :x="pin[0]" :y="pin[1] + 3.5" text-anchor="middle" font-size="7.5" font-weight="700" fill="white">{{ variant === 'pings' ? initials[index] : index + 1 }}</text>
                </g>
            </svg>
        </div>
    </div>
</template>

<script setup>
defineProps({
    variant: { type: String, default: 'default' },
})

const pins = [[40, 128], [96, 72], [168, 104], [236, 44], [284, 112]]
const initials = ['BV', 'DW', 'JM', 'NP', 'TG']
</script>

<style scoped>
.scene-route {
    stroke-dasharray: 520;
    animation: draw 4s ease-in-out infinite;
}

.scene-pin {
    transform-box: fill-box;
    transform-origin: center;
    animation: pop 4s ease-out infinite;
}

.scene-pill {
    animation: glow 2s ease-in-out infinite;
}

@keyframes draw {
    0%, 25% { stroke-dashoffset: 520; }
    75%, 100% { stroke-dashoffset: 0; }
}

@keyframes pop {
    0% { transform: scale(0); }
    10%, 100% { transform: scale(1); }
}

.scene-pulse {
    transform-box: fill-box;
    transform-origin: center;
    animation: pulse 2s ease-out infinite;
}

@keyframes pulse {
    from { transform: scale(0.6); opacity: 1; }
    to { transform: scale(1.6); opacity: 0; }
}

@keyframes glow {
    50% { box-shadow: 0 0 0 4px rgb(198 255 0 / 0.8); }
}
</style>
