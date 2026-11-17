<template>
    <div class="relative h-[17rem] w-[24rem] bg-gray-50 p-4 text-gray-900 dark:bg-slate-800 dark:text-slate-100">
        <div v-if="variant !== 'undo'" class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
            <p class="text-sm font-bold">Storing inplannen</p>
            <p class="text-[11px] text-gray-500">Bij Bas Visser, dinsdag 09:00</p>
            <div class="mt-2.5 flex flex-col gap-1.5">
                <div v-for="(choice, index) in choices" :key="choice.label"
                    class="flex items-start gap-2 rounded-lg border-2 border-gray-200 px-2.5 py-1.5 dark:border-slate-700"
                    :class="index === 0 ? 'scene-choice' : ''">
                    <span class="mt-0.5 size-3 flex-none rounded-full border border-gray-300 dark:border-slate-600" :class="index === 0 ? 'scene-radio' : ''" />
                    <span>
                        <span class="block text-[11px] font-semibold">{{ choice.label }}</span>
                        <span class="block text-[10px] text-gray-500">{{ choice.description }}</span>
                    </span>
                </div>
            </div>
            <div class="mt-2.5 flex justify-end">
                <span class="scene-confirm rounded-lg bg-lavoro-blue px-3 py-1 text-[11px] font-semibold text-white">Inplannen</span>
            </div>
        </div>
        <div class="scene-toast absolute inset-x-4 bottom-4 flex items-center gap-2 overflow-hidden rounded-xl bg-sidebar-bg py-1.5 pl-3 pr-1.5 text-[11px] text-white shadow-xl"
            :class="variant === 'undo' ? 'scene-toast-only' : ''">
            <span class="flex-1">Ingepland bij Bas Visser</span>
            <span class="scene-undo rounded-lg bg-sidebar-card px-2 py-1 font-semibold">Ongedaan maken</span>
            <span class="scene-countdown absolute inset-x-0 bottom-0 h-0.5 origin-left bg-sidebar-indicator" />
        </div>
        <div v-if="variant === 'undo'" class="absolute inset-x-4 top-4 rounded-xl bg-white p-3 text-[11px] shadow-sm ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
            <p class="font-semibold">Op het bakje</p>
            <div class="scene-back mt-1.5 rounded-lg bg-sidebar-card px-2 py-1.5 text-white">
                <p class="text-[8px] uppercase tracking-wider text-slate-400">Storing</p>
                <p class="text-[10px] font-semibold">Ketel lekt</p>
            </div>
        </div>
        <svg class="absolute left-0 top-0" :class="variant === 'undo' ? 'scene-cursor-undo' : 'scene-cursor'" width="16" height="19" viewBox="0 0 26 30">
            <path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 16.8 L20 16.8 Z" fill="white" stroke="#081020" stroke-width="2" />
        </svg>
    </div>
</template>

<script setup>
/**
 * De vraag na het loslaten op de planner: een nieuwe werkbon of een open
 * werkbon van de klant, daarna de balk met Ongedaan maken. Als `undo`: alleen
 * die balk, en de storing die terug op het bakje komt.
 */
defineProps({
    variant: { type: String, default: 'default' },
})

const choices = [
    { label: 'Nieuwe werkbon', description: 'Voor Fam. Kramer, met deze storing erop.' },
    { label: 'WB-0014', description: 'Open werkbon van Fam. Kramer.' },
]
</script>

<style scoped>
.scene-cursor {
    animation: cursor 5s ease-in-out infinite;
}

.scene-cursor-undo {
    animation: cursor-undo 4s ease-in-out infinite;
}

.scene-choice {
    animation: choice 5s infinite;
}

.scene-radio {
    animation: radio 5s infinite;
}

.scene-confirm {
    animation: confirm 5s infinite;
}

.scene-toast {
    opacity: 0;
    animation: toast 5s ease-out infinite;
}

.scene-toast-only {
    opacity: 1;
    animation: none;
}

.scene-countdown {
    animation: countdown 5s linear infinite;
}

.scene-toast-only .scene-countdown {
    animation: countdown-undo 4s linear infinite;
}

.scene-toast-only .scene-undo {
    animation: press-undo 4s infinite;
}

.scene-back {
    animation: back 4s infinite;
}

@keyframes cursor {
    0% { transform: translate(300px, 230px); }
    18%, 30% { transform: translate(40px, 96px); }
    50%, 58% { transform: translate(318px, 186px); }
    100% { transform: translate(318px, 186px); }
}

@keyframes choice {
    0%, 24% { border-color: rgb(229 231 235); }
    26%, 100% { border-color: var(--color-lavoro-blue); }
}

@keyframes radio {
    0%, 24% { box-shadow: none; }
    26%, 100% { box-shadow: inset 0 0 0 3px white; background: var(--color-lavoro-blue); }
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

@keyframes countdown {
    0%, 64% { transform: scaleX(1); }
    100% { transform: scaleX(0); }
}

@keyframes countdown-undo {
    0% { transform: scaleX(1); }
    45%, 100% { transform: scaleX(0.55); }
}

@keyframes cursor-undo {
    0% { transform: translate(200px, 120px); }
    30%, 100% { transform: translate(300px, 222px); }
}

@keyframes press-undo {
    0%, 32% { transform: scale(1); }
    36% { transform: scale(0.9); }
    40%, 100% { transform: scale(1); }
}

@keyframes back {
    0%, 38% { opacity: 0; transform: translateY(12px); }
    48%, 100% { opacity: 1; transform: translateY(0); }
}
</style>
