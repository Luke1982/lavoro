<template>
    <div class="relative h-[14rem] w-[22rem] bg-gray-50 p-4 text-gray-900 dark:bg-slate-800 dark:text-slate-100">
        <div class="flex items-center justify-between">
            <p class="text-sm font-bold">{{ machine ? 'Machines / Daikin RXM25R' : 'Storingen' }}</p>
            <span class="scene-button inline-flex items-center gap-1.5 rounded-md bg-sidebar-bg px-2.5 py-1.5 text-[11px] font-semibold text-white">
                <svg class="size-3.5 text-sidebar-indicator" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" /></svg>
                Op het bakje
            </span>
        </div>
        <div v-if="machine" class="mt-3 flex items-start gap-3 rounded-lg bg-white p-3 ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
            <span class="size-12 flex-none rounded-md bg-gray-100 ring-1 ring-gray-200 dark:bg-slate-800 dark:ring-slate-700" />
            <span class="min-w-0 text-[11px]">
                <span class="block text-sm font-bold">Daikin RXM25R</span>
                <span class="mt-1 inline-block rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-semibold text-green-700 dark:bg-green-900/20 dark:text-green-300">Actief</span>
                <span class="mt-1 block text-gray-500 dark:text-slate-400">Fam. Kramer · keuring 12-03-2027</span>
            </span>
        </div>
        <div v-else class="mt-3 divide-y divide-gray-100 rounded-lg bg-white ring-1 ring-gray-200 dark:divide-slate-700 dark:bg-slate-900 dark:ring-slate-700">
            <div v-for="(row, index) in rows" :key="row.title" class="flex items-center gap-2.5 px-3 py-2 text-[11px]">
                <span class="size-3.5 flex-none rounded border border-gray-300 dark:border-slate-600" :class="index === 0 ? 'scene-check' : ''" />
                <span class="font-semibold">{{ row.title }}</span>
                <span class="truncate text-gray-500 dark:text-slate-400">{{ row.customer }}</span>
            </div>
        </div>
        <div class="scene-pill absolute bottom-3 right-3 flex items-center gap-2 rounded-full bg-sidebar-bg py-1 pl-1 pr-3 text-[11px] font-bold text-white shadow-lg">
            <span class="flex size-6 items-center justify-center rounded-full bg-sidebar-card">
                <svg class="size-3.5 text-sidebar-indicator" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" /></svg>
            </span>
            Bakje
            <span class="relative inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-sidebar-indicator px-1 text-[10px] text-sidebar-bg">
                <span class="scene-count-before">0</span>
                <span class="scene-count-after absolute">1</span>
            </span>
        </div>
        <div class="scene-chip absolute right-6 top-4 rounded-lg bg-sidebar-card px-2 py-1 text-[10px] font-semibold text-white shadow-xl ring-1 ring-sidebar-border">
            {{ machine ? 'Daikin RXM25R' : 'Ketel lekt' }}
        </div>
        <svg class="absolute left-3 top-14" :class="machine ? 'scene-cursor-direct' : 'scene-cursor'" width="16" height="19" viewBox="0 0 26 30">
            <path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 16.8 L20 16.8 Z" fill="white" stroke="#081020" stroke-width="2" />
        </svg>
    </div>
</template>

<script setup>
import { computed } from 'vue'

/**
 * Iets op het bakje leggen: een storing aanvinken in de lijst, op "Op het
 * bakje" klikken, en het vliegt naar het bakje rechtsonder. Als `machine`: de
 * knop bovenaan de pagina van een machine, zonder iets aan te vinken.
 */
const props = defineProps({
    variant: { type: String, default: 'default' },
})

const machine = computed(() => props.variant === 'machine')

const rows = [
    { title: 'Ketel lekt', customer: 'Fam. Kramer' },
    { title: 'Airco tikt', customer: 'Bakker Installatie' },
    { title: 'Geen warm water', customer: 'T. Molenaar' },
]
</script>

<style scoped>
.scene-cursor {
    animation: cursor 5s ease-in-out infinite;
}

.scene-cursor-direct {
    animation: cursor-direct 5s ease-in-out infinite;
}

.scene-check {
    animation: check 5s infinite;
}

.scene-button {
    animation: press 5s infinite;
}

.scene-chip {
    opacity: 0;
    animation: chip 5s cubic-bezier(.5, 0, .3, 1) infinite;
}

.scene-pill {
    animation: bump 5s infinite;
}

.scene-count-before {
    animation: before 5s infinite;
}

.scene-count-after {
    animation: after 5s infinite;
}

@keyframes cursor {
    0% { transform: translate(-6px, 8px); }
    14%, 22% { transform: translate(6px, 18px); }
    36%, 46% { transform: translate(262px, -38px); }
    70%, 100% { transform: translate(262px, -38px); opacity: 1; }
}

@keyframes cursor-direct {
    0%, 12% { transform: translate(40px, 30px); }
    36%, 100% { transform: translate(262px, -38px); }
}

@keyframes check {
    0%, 16% { background: transparent; }
    18%, 100% { background: var(--color-lavoro-blue); border-color: var(--color-lavoro-blue); }
}

@keyframes press {
    0%, 38% { transform: scale(1); }
    41% { transform: scale(0.92); }
    45%, 100% { transform: scale(1); }
}

@keyframes chip {
    0%, 42% { opacity: 0; transform: translate(0, 0) scale(1); }
    46% { opacity: 1; transform: translate(0, 0) scale(1); }
    68% { opacity: 1; transform: translate(10px, 158px) scale(0.6); }
    72%, 100% { opacity: 0; transform: translate(10px, 158px) scale(0.4); }
}

@keyframes bump {
    0%, 68% { transform: scale(1); }
    72% { transform: scale(1.12); }
    78%, 100% { transform: scale(1); }
}

@keyframes before {
    0%, 69% { opacity: 1; }
    71%, 100% { opacity: 0; }
}

@keyframes after {
    0%, 69% { opacity: 0; }
    71%, 100% { opacity: 1; }
}
</style>
