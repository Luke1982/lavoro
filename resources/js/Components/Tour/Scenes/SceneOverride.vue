<template>
    <div class="w-[21rem] p-5 text-gray-900 dark:text-slate-100">
        <div class="flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                <AlertTriangle class="size-5 text-amber-600 dark:text-amber-400" />
            </span>
            <div>
                <p class="text-sm font-semibold">Niet beschikbaar</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    Je staat op het punt een afspraak in te plannen op een tijdstip waarop de volgende monteur(s) niet beschikbaar zijn:
                </p>
                <p class="mt-1.5 text-xs font-medium">Jan de Vries <span class="font-normal text-gray-500 dark:text-slate-400">— Vakantie</span></p>
                <p class="mt-1.5 text-xs text-gray-500 dark:text-slate-400">Wil je toch doorgaan?</p>
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-2 text-xs font-semibold">
            <span class="rounded-md px-3 py-1.5 ring-1 ring-gray-300 dark:ring-slate-600">Annuleren</span>
            <span class="scene-confirm rounded-md bg-amber-600 px-3 py-1.5 text-white">Toch inplannen</span>
        </div>
    </div>
</template>

<script setup>
import { AlertTriangle } from '@lucide/vue'
</script>

<style scoped>
.scene-confirm {
    animation: glow 2.2s ease-in-out infinite;
}

@keyframes glow {
    50% { box-shadow: 0 0 0 4px rgb(217 119 6 / 0.25); }
}
</style>
