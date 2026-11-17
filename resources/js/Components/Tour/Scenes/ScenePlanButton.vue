<template>
    <div class="w-[24rem] p-4 text-gray-900 dark:text-slate-100">
        <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-200 dark:bg-slate-800 dark:ring-slate-700">
            <div class="min-w-0">
                <p class="text-xs font-semibold">WB-1042 · Bakker Installatie</p>
                <p class="text-[11px] text-gray-500">Fase: In te plannen</p>
            </div>
            <span class="scene-button relative rounded-md bg-lavoro-blue px-2.5 py-1 text-[11px] font-semibold text-white">
                Inplannen
                <span class="scene-ripple absolute inset-0 rounded-md" />
            </span>
        </div>
        <svg class="mx-auto my-1 block text-lavoro-blue" width="24" height="26" viewBox="0 0 24 26">
            <path class="scene-arrow" d="M12 2v18M5 14l7 7 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
        <div class="flex gap-2">
            <div class="flex-1 overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-slate-700">
                <div v-for="row in 3" :key="row" class="relative h-8 border-b border-gray-100 last:border-b-0 dark:border-slate-700">
                    <div class="absolute inset-y-1 left-1 w-16 rounded bg-sky-200/80" />
                    <div class="scene-gap absolute inset-y-1 rounded border-2 border-dashed border-emerald-500/70 bg-emerald-400/15 px-1 text-[9px] font-medium text-emerald-700"
                        :style="{ left: (row * 18 + 20) + '%', width: (34 - row * 6) + '%', animationDelay: (row * 0.25) + 's' }">{{ ['2u 30m', '1u 45m', '1u'][row - 1] }}</div>
                </div>
            </div>
            <div class="scene-card w-24 rounded-md bg-emerald-50 p-1.5 text-[10px] ring-2 ring-emerald-500">
                <p class="font-semibold">#1042</p>
                <p class="text-lavoro-blue">Bakker</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scene-ripple {
    animation: ripple 3s ease-out infinite;
}

.scene-button {
    animation: press 3s infinite;
}

.scene-arrow {
    stroke-dasharray: 40;
    animation: arrow 3s ease-in-out infinite;
}

.scene-gap {
    animation: gap 3s ease-in-out infinite;
}

.scene-card {
    animation: card 3s ease-in-out infinite;
}

@keyframes press {
    0%, 14% { transform: scale(1); }
    18% { transform: scale(0.9); }
    24%, 100% { transform: scale(1); }
}

@keyframes ripple {
    0%, 16% { box-shadow: 0 0 0 0 rgb(198 255 0 / 0.9); }
    40%, 100% { box-shadow: 0 0 0 12px rgb(198 255 0 / 0); }
}

@keyframes arrow {
    0%, 20% { stroke-dashoffset: 40; }
    40%, 100% { stroke-dashoffset: 0; }
}

@keyframes gap {
    0%, 38% { opacity: 0; }
    50%, 100% { opacity: 1; }
}

@keyframes card {
    0%, 38% { opacity: 0.2; }
    50%, 100% { opacity: 1; }
}
</style>
