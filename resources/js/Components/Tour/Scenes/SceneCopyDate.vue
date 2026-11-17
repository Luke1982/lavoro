<template>
    <div class="w-[21rem] p-5 text-gray-900 dark:text-slate-100">
        <p class="text-sm font-semibold">Afspraak kopiëren naar datum</p>
        <p class="mt-2 text-xs text-gray-600 dark:text-slate-300">
            De kopie van #8231 Onderhoud (18-11-2026) krijgt dezelfde tijd, monteurs en werkbon.
        </p>
        <p class="mt-3 text-[11px] font-medium text-gray-700 dark:text-slate-300">Datum</p>
        <div class="mt-1 flex items-center justify-between rounded-md px-2.5 py-1.5 text-sm ring-1 ring-lavoro-blue">
            <span class="scene-date overflow-hidden whitespace-nowrap">12-12-2026</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="text-gray-400"><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>
        </div>
        <div class="mt-4 flex justify-end gap-2 text-xs font-medium">
            <span class="rounded-md px-3 py-1.5 ring-1 ring-gray-300 dark:ring-slate-600">Annuleren</span>
            <span class="scene-copy rounded-md bg-lavoro-blue px-3 py-1.5 text-white">Kopiëren</span>
        </div>
    </div>
</template>

<style scoped>
.scene-date {
    display: inline-block;
    width: 10ch;
    animation: type 4s steps(10) infinite;
}

.scene-copy {
    animation: press 4s infinite;
}

@keyframes type {
    0%, 10% { width: 0; }
    50%, 100% { width: 10ch; }
}

@keyframes press {
    0%, 66% { transform: scale(1); box-shadow: none; }
    70% { transform: scale(0.92); box-shadow: 0 0 0 4px rgb(198 255 0 / 0.7); }
    78%, 100% { transform: scale(1); box-shadow: none; }
}
</style>
