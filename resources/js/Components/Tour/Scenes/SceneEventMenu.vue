<template>
    <div class="relative h-[17.5rem] bg-gray-50 p-4 dark:bg-slate-800" :class="copy ? 'w-[27rem]' : 'w-[20rem]'">
        <div class="w-32 rounded-md border-l-4 border-sky-500 bg-sky-100 dark:bg-sky-900/30 px-2 py-1.5 text-[11px] leading-tight text-gray-900 dark:text-slate-100 shadow-sm">
            <div class="font-semibold">Onderhoud</div>
            <div class="text-gray-600 dark:text-slate-400">Bakker Installatie</div>
            <div class="text-gray-600 dark:text-slate-400">09:00 – 11:00</div>
        </div>
        <div class="scene-menu absolute left-28 top-12 w-48 rounded-md bg-white py-1 text-[12px] shadow-xl ring-1 ring-black/10 dark:bg-slate-900 dark:text-slate-100 dark:ring-white/10">
            <div class="flex justify-between px-3 py-0.5">Wijzig type <span class="text-gray-400">›</span></div>
            <div class="flex justify-between px-3 py-0.5">Monteurs (2) <span class="text-gray-400">›</span></div>
            <div class="px-3 py-0.5">Bewerken…</div>
            <div class="my-0.5 border-t border-gray-100 dark:border-slate-700" />
            <div class="px-3 py-0.5">Markeer als voorlopig</div>
            <div class="flex justify-between px-3 py-0.5" :class="copy ? 'scene-hot' : ''">Kopiëren <span class="text-gray-400">›</span></div>
            <div class="my-0.5 border-t border-gray-100 dark:border-slate-700" />
            <div class="px-3 py-0.5">Open werkbon #1042</div>
            <div class="px-3 py-0.5" :class="copy ? '' : 'scene-hot'">Stuur afspraakbevestiging</div>
            <div class="px-3 py-0.5">Open klant</div>
            <div class="my-0.5 border-t border-gray-100 dark:border-slate-700" />
            <div class="px-3 py-0.5 text-red-600 dark:text-red-400">Verwijderen</div>
        </div>
        <div v-if="copy" class="scene-sub absolute left-[18.5rem] top-[7.1rem] w-36 rounded-md bg-white py-1 text-[12px] shadow-xl ring-1 ring-black/10 dark:bg-slate-900 dark:text-slate-100 dark:ring-white/10">
            <div v-for="(label, index) in copyItems" :key="label" class="scene-cycle px-3 py-0.5" :style="{ animationDelay: (index * 1.1) + 's' }">{{ label }}</div>
        </div>
        <div v-else class="scene-sent absolute bottom-3 right-3 flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-[11px] font-medium text-white shadow-lg">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v12H4z" /><path d="m4 7 8 6 8-6" /></svg>
            Bevestiging verstuurd
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: { type: String, default: 'copy' },
})

const copy = computed(() => props.variant === 'copy')
const copyItems = ['Naar morgen', 'Rest van de week', 'Naar volgende week', 'Naar datum…']
</script>

<style scoped>
.scene-menu {
    transform-origin: 0 0;
    animation: menu-pop 0.4s ease-out both;
}

.scene-hot {
    background: var(--tour-hot);
    color: var(--tour-hot-text);
    font-weight: 600;
}

.scene-sub {
    animation: menu-pop 0.4s 0.5s ease-out both;
    transform-origin: 0 0;
}

.scene-cycle {
    animation: cycle 4.4s infinite;
}

.scene-sent {
    animation: sent 3.6s ease-out infinite;
}

@keyframes menu-pop {
    from { opacity: 0; transform: scale(0.9); }
}

@keyframes cycle {
    0%, 2% { background: transparent; }
    5%, 22% { background: var(--tour-hot); color: var(--tour-hot-text); }
    25%, 100% { background: transparent; }
}

@keyframes sent {
    0%, 35% { opacity: 0; transform: translateY(8px); }
    45%, 90% { opacity: 1; transform: translateY(0); }
    100% { opacity: 0; transform: translateY(0); }
}
</style>
