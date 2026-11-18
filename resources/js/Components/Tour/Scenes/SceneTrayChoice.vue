<template>
    <div class="relative h-[19rem] w-[24rem] bg-gray-50 p-4 text-gray-900 dark:bg-slate-800 dark:text-slate-100">
        <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
            <p class="text-sm font-bold">{{ scene.title }}</p>
            <p class="text-[11px] text-gray-500 dark:text-slate-400">Bij Bas Visser, dinsdag 09:00</p>
            <div v-for="group in scene.groups" :key="group.title" class="mt-2 flex flex-col gap-1">
                <p class="text-[11px] font-bold">
                    {{ group.title }}
                    <span v-if="group.subtitle" class="font-normal text-gray-500 dark:text-slate-400">· {{ group.subtitle }}</span>
                </p>
                <div v-for="choice in group.choices" :key="choice.label"
                    class="flex items-start gap-2 rounded-lg border-2 border-gray-200 px-2.5 py-1 dark:border-slate-700"
                    :class="choice.look && 'scene-' + choice.look">
                    <span class="scene-radio mt-0.5 size-3 flex-none rounded-full border border-gray-300 dark:border-slate-600" />
                    <span>
                        <span class="block text-[11px] font-semibold">{{ choice.label }}</span>
                        <span v-if="choice.description" class="block text-[10px] text-gray-500 dark:text-slate-400">{{ choice.description }}</span>
                    </span>
                </div>
            </div>
            <div class="mt-2.5 flex justify-end">
                <span class="scene-confirm rounded-lg bg-lavoro-blue px-3 py-1 text-[11px] font-semibold text-white">Inplannen</span>
            </div>
        </div>
        <div class="scene-toast absolute inset-x-4 bottom-4 rounded-xl bg-sidebar-bg px-3 py-2 text-[11px] text-white shadow-xl">
            {{ scene.toast }}
        </div>
        <svg class="scene-cursor absolute left-0 top-0" :style="scene.cursor" width="16" height="19" viewBox="0 0 26 30">
            <path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 16.8 L20 16.8 Z" fill="white" stroke="#081020" stroke-width="2" />
        </svg>
    </div>
</template>

<script setup>
import { computed } from 'vue'

/**
 * De vraag na het loslaten op de planner, en de melding daarna. Gewoon: één
 * vraag voor alles, een nieuwe werkbon of een open werkbon van de klant. Als
 * `mixed`: een stapel die niet allemaal dezelfde kant op gaat, met een keuze per
 * storing, waarvan er een op het bakje blijft liggen.
 *
 * Een keuze die `picked` is, wordt halverwege aangeklikt; een die `chosen` is,
 * staat al aan en gaat uit als de cursor een andere in dezelfde groep kiest
 * (`unpicked`). De cursor gaat naar de plek in --pick en dan naar Inplannen.
 */
const props = defineProps({
    variant: { type: String, default: 'default' },
})

const SCENES = {
    default: {
        title: 'Storing inplannen',
        groups: [{
            title: 'Op welke werkbon?',
            choices: [
                { label: 'Nieuwe werkbon', description: 'Voor Fam. Kramer, met de storing erop.', look: 'picked' },
                { label: 'Bij open werkbon WB-0014', description: 'Nog niet ingepland.' },
            ],
        }],
        toast: 'Werkbon WB-0016 ingepland bij Bas Visser, dinsdag 09:00.',
        cursor: { '--pick-x': '40px', '--pick-y': '104px', '--confirm-y': '200px' },
    },
    mixed: {
        title: '2 storingen inplannen',
        groups: [
            {
                title: 'Ketel lekt',
                subtitle: 'staat op WB-0014',
                choices: [{ label: 'Werkbon WB-0014', look: 'chosen' }, { label: 'Niet inplannen' }],
            },
            {
                title: 'Radiator koud',
                subtitle: 'Fam. Jansen',
                choices: [{ label: 'Nieuwe werkbon', look: 'unpicked' }, { label: 'Niet inplannen', look: 'picked' }],
            },
        ],
        toast: 'Werkbon WB-0014 ingepland bij Bas Visser. "Radiator koud" blijft op het bakje.',
        cursor: { '--pick-x': '40px', '--pick-y': '222px', '--confirm-y': '260px' },
    },
}

const scene = computed(() => SCENES[props.variant] ?? SCENES.default)
</script>

<style scoped>
.scene-cursor {
    animation: cursor 5s ease-in-out infinite;
}

.scene-chosen {
    border-color: var(--color-lavoro-blue);
}

.scene-chosen .scene-radio {
    box-shadow: inset 0 0 0 3px var(--tour-surface);
    background: var(--color-lavoro-blue);
}

.scene-picked {
    animation: pick 5s infinite;
}

.scene-picked .scene-radio {
    animation: pick-radio 5s infinite;
}

.scene-unpicked {
    animation: unpick 5s infinite;
}

.scene-unpicked .scene-radio {
    animation: unpick-radio 5s infinite;
}

.scene-confirm {
    animation: confirm 5s infinite;
}

.scene-toast {
    opacity: 0;
    animation: toast 5s ease-out infinite;
}

@keyframes cursor {
    0% { transform: translate(300px, 260px); }
    18%, 30% { transform: translate(var(--pick-x), var(--pick-y)); }
    50%, 58%, 100% { transform: translate(318px, var(--confirm-y)); }
}

@keyframes pick {
    0%, 24% { border-color: var(--tour-line); }
    26%, 100% { border-color: var(--color-lavoro-blue); }
}

@keyframes unpick {
    0%, 24% { border-color: var(--color-lavoro-blue); }
    26%, 100% { border-color: var(--tour-line); }
}

@keyframes pick-radio {
    0%, 24% { box-shadow: none; }
    26%, 100% { box-shadow: inset 0 0 0 3px var(--tour-surface); background: var(--color-lavoro-blue); }
}

@keyframes unpick-radio {
    0%, 24% { box-shadow: inset 0 0 0 3px var(--tour-surface); background: var(--color-lavoro-blue); }
    26%, 100% { box-shadow: none; }
}

@keyframes confirm {
    0%, 52% { transform: scale(1); }
    55% { transform: scale(0.9); }
    59%, 100% { transform: scale(1); }
}

@keyframes toast {
    0%, 60% { opacity: 0; transform: translateY(10px); }
    66%, 96% { opacity: 1; transform: translateY(0); }
    100% { opacity: 0; transform: translateY(0); }
}
</style>
