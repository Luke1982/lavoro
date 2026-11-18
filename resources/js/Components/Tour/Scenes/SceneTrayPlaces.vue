<template>
    <div class="relative h-[16rem] w-[26rem] bg-gray-50 p-4 text-gray-900 dark:bg-slate-800 dark:text-slate-100"
        :style="{ '--cycle': places.length * STAY + 's' }">
        <p class="text-[11px] font-bold text-gray-500 dark:text-slate-400">Een machine van het bakje kan naar</p>
        <div class="mt-2 grid grid-cols-2 gap-2">
            <div v-for="(place, index) in places" :key="place.where" :style="{ animationDelay: (index - places.length) * STAY + 's' }"
                class="scene-place flex h-14 flex-col justify-center rounded-lg border-2 border-dashed border-gray-200 bg-white px-3 dark:border-slate-700 dark:bg-slate-900">
                <span class="text-[11px] font-semibold">{{ place.where }}</span>
                <span class="text-[10px] text-gray-500 dark:text-slate-400">{{ place.then }}</span>
            </div>
        </div>
        <div class="scene-machine absolute left-0 top-0 rounded-lg bg-sidebar-card px-2 py-1 text-white shadow-xl ring-1 ring-sidebar-border">
            <p class="text-[8px] uppercase tracking-wider text-slate-400">Machine</p>
            <p class="text-[10px] font-semibold">Daikin RXM25R</p>
        </div>
    </div>
</template>

<script setup>
/**
 * Waar een machine van het bakje heen kan, en wat er dan gebeurt. De machine
 * zweeft langs de plekken, boven de hoek van elke plek zodat de naam leesbaar
 * blijft, en elke plek licht op zoals tijdens het slepen. Twee seconden per plek;
 * de route van de machine in de stijlen hoort bij deze zes plekken.
 */
const STAY = 2

const places = [
    { where: 'De dag van een monteur', then: 'Ingepland, met een keuring' },
    { where: 'Keuringen op een werkbon', then: 'Er komt een keuring bij' },
    { where: 'Een andere machine', then: 'Wordt een onderdeel ervan' },
    { where: 'Een klant of locatie', then: 'Verhuist erheen' },
    { where: 'Machines op een contract', then: 'Valt onder het contract' },
    { where: 'Werkbonnen van een project', then: 'Nieuwe werkbon met keuring' },
]
</script>

<style scoped>
.scene-place {
    animation: place var(--cycle) infinite;
}

.scene-machine {
    animation: machine var(--cycle) cubic-bezier(.5, 0, .3, 1) infinite;
}

@keyframes place {
    0%, 2% { border-color: var(--tour-line); background: var(--tour-surface); }
    4%, 14% { border-color: var(--color-lavoro-blue); background: color-mix(in srgb, var(--color-lavoro-blue) 12%, var(--tour-surface)); }
    16.6%, 100% { border-color: var(--tour-line); background: var(--tour-surface); }
}

@keyframes machine {
    0%, 14% { transform: translate(108px, 16px); }
    16.6%, 30% { transform: translate(304px, 16px); }
    33.3%, 47% { transform: translate(108px, 80px); }
    50%, 64% { transform: translate(304px, 80px); }
    66.6%, 80% { transform: translate(108px, 144px); }
    83.3%, 97% { transform: translate(304px, 144px); }
    100% { transform: translate(108px, 16px); }
}
</style>
