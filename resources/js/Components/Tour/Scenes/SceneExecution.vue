<template>
    <div class="w-[20rem] p-5 text-gray-900 dark:text-slate-100">
        <p class="text-sm font-semibold">Tijden en handtekening</p>
        <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
            <div v-for="field in fields" :key="field.label">
                <div class="text-gray-500 dark:text-slate-400">{{ field.label }}</div>
                <div class="scene-field mt-0.5 rounded-md px-2 py-1 text-[13px] ring-1 ring-gray-300 dark:ring-slate-600"
                    :class="field.changed ? 'scene-changed' : ''">{{ field.value }}</div>
            </div>
        </div>
        <div class="mt-3 text-[11px] text-gray-500 dark:text-slate-400">Handtekening</div>
        <div class="mt-1 rounded-lg bg-gray-50 ring-1 ring-gray-200 dark:bg-slate-800 dark:ring-slate-700">
            <svg viewBox="0 0 280 80" class="h-20 w-full">
                <path class="scene-signature" d="M18 56 C 30 20, 44 18, 48 46 S 64 70, 76 40 S 96 12, 104 44 C 108 60, 120 62, 130 40 C 138 24, 150 30, 152 48 C 154 60, 170 58, 182 36 C 190 22, 204 26, 206 46 C 208 60, 236 52, 262 30"
                    fill="none" stroke="#081020" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <div class="mt-3 flex justify-end">
            <span class="scene-save rounded-md bg-lavoro-blue px-3 py-1.5 text-xs font-semibold text-white">Opslaan</span>
        </div>
    </div>
</template>

<script setup>
const fields = [
    { label: 'Starttijd', value: '08:00' },
    { label: 'Eindtijd', value: '12:30', changed: true },
    { label: 'Reistijd (min)', value: '25', changed: true },
    { label: 'Pauze (min)', value: '15' },
]
</script>

<style scoped>
.scene-signature {
    stroke-dasharray: 700;
    stroke-dashoffset: 0;
    animation: draw 4.5s ease-in-out infinite;
}

.scene-changed {
    animation: changed 4.5s infinite;
}

.scene-save {
    animation: press 4.5s infinite;
}

@keyframes draw {
    0%, 20% { stroke-dashoffset: 700; }
    70%, 100% { stroke-dashoffset: 0; }
}

@keyframes changed {
    0%, 6% { box-shadow: 0 0 0 0 rgb(37 99 255 / 0); }
    12%, 20% { box-shadow: 0 0 0 2px var(--color-lavoro-blue); }
    26%, 100% { box-shadow: 0 0 0 0 rgb(37 99 255 / 0); }
}

@keyframes press {
    0%, 80% { transform: scale(1); }
    84% { transform: scale(0.92); }
    90%, 100% { transform: scale(1); }
}
</style>
