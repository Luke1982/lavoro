<template>
    <div class="relative flex h-[13rem] w-[27rem] gap-3 bg-gray-50 p-4 text-gray-900 dark:bg-slate-800 dark:text-slate-100">
        <div class="flex-1 overflow-hidden rounded-lg bg-white ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
            <div class="flex border-b border-gray-100 pl-20 text-[9px] text-gray-400 dark:border-slate-700">
                <span v-for="hour in hours" :key="hour" class="flex-1 py-1">{{ hour }}</span>
            </div>
            <div v-for="name in technicians" :key="name" class="relative flex h-14 items-center border-b border-gray-100 last:border-b-0 dark:border-slate-700">
                <span class="w-20 shrink-0 truncate px-2 text-[10px] font-semibold">{{ name }}</span>
                <span class="h-full flex-1 border-l border-gray-100 dark:border-slate-700" />
            </div>
            <div class="scene-landed absolute left-[7.6rem] top-[3.2rem] h-10 w-[4.4rem] rounded-md border-l-4 border-sky-500 bg-sky-100 px-1.5 py-1 text-[9px] leading-tight text-gray-900">
                <p class="font-semibold">Ketel lekt</p>
                <p class="text-gray-600">09:00 – 11:00</p>
            </div>
        </div>
        <div class="w-[8.5rem] shrink-0 rounded-2xl bg-sidebar-bg p-2.5 text-white">
            <p class="text-[11px] font-bold">Bakje</p>
            <div class="scene-source mt-2 rounded-xl border border-sidebar-border bg-sidebar-card px-2 py-1.5">
                <p class="text-[8px] uppercase tracking-wider text-slate-400">Storing</p>
                <p class="text-[10px] font-semibold">Ketel lekt</p>
                <p class="text-[9px] text-slate-400">Fam. Kramer</p>
            </div>
            <div class="mt-1.5 rounded-xl border border-sidebar-border bg-sidebar-card px-2 py-1.5 opacity-60">
                <p class="text-[8px] uppercase tracking-wider text-slate-400">Machine</p>
                <p class="text-[10px] font-semibold">Warmtepomp</p>
            </div>
        </div>
        <div class="scene-ghost absolute right-[1.6rem] top-[3.05rem] w-[7.4rem] rounded-xl border border-sidebar-border bg-sidebar-card px-2 py-1.5 text-white shadow-2xl">
            <p class="text-[8px] uppercase tracking-wider text-slate-400">Storing</p>
            <p class="text-[10px] font-semibold">Ketel lekt</p>
            <p class="text-[9px] text-slate-400">Fam. Kramer</p>
        </div>
    </div>
</template>

<script setup>
/**
 * Van het bakje naar de planner: de storing gaat van het bakje naar de rij van
 * een monteur en wordt daar een afspraak, op het uur waar hij werd losgelaten.
 */
defineProps({
    variant: { type: String, default: 'default' },
})

const hours = ['08', '09', '10', '11']
const technicians = ['Bas Visser', 'Daan Willems']
</script>

<style scoped>
.scene-ghost {
    opacity: 0;
    animation: ghost 4.5s cubic-bezier(.45, 0, .25, 1) infinite;
}

.scene-source {
    animation: source 4.5s infinite;
}

.scene-landed {
    opacity: 0;
    animation: landed 4.5s ease-out infinite;
}

@keyframes ghost {
    0%, 10% { opacity: 0; transform: translate(0, 0) rotate(0); }
    16% { opacity: 1; transform: translate(-4px, -4px) rotate(-3deg); }
    56% { opacity: 1; transform: translate(-176px, 4px) rotate(-2deg) scale(0.85); }
    62%, 100% { opacity: 0; transform: translate(-176px, 4px) scale(0.8); }
}

@keyframes source {
    0%, 12% { opacity: 1; }
    16%, 60% { opacity: 0.35; }
    66%, 100% { opacity: 1; }
}

@keyframes landed {
    0%, 58% { opacity: 0; transform: scale(0.85); }
    64%, 92% { opacity: 1; transform: scale(1); }
    100% { opacity: 0; transform: scale(1); }
}
</style>
