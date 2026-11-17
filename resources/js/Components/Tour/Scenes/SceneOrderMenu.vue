<template>
    <div class="relative h-[13.5rem] w-[19rem] bg-gray-50 p-4 dark:bg-slate-800">
        <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
            <div class="flex items-center justify-between text-sm font-semibold">#1042 <span class="text-xs font-normal text-gray-400">12-11-2026</span></div>
            <div class="text-xs font-medium text-lavoro-blue">Bakker Installatietechniek</div>
            <div class="text-xs text-gray-500">Utrecht</div>
            <div class="mt-1 text-xs text-gray-600 dark:text-slate-400">Onderhoud cv-ketel en filters vervangen</div>
        </div>
        <div class="scene-menu absolute left-28 top-14 w-40 rounded-md bg-white py-1 text-[13px] shadow-xl ring-1 ring-black/10 dark:bg-slate-900 dark:ring-white/10">
            <div class="px-3 py-1 text-gray-400">Werkbon #1042</div>
            <div class="scene-item scene-item-first px-3 py-1">Openen</div>
            <div class="my-1 border-t border-gray-100 dark:border-slate-700" />
            <div class="scene-item scene-item-second px-3 py-1 text-red-600">Verwijderen</div>
        </div>
        <svg class="scene-cursor absolute left-24 top-12" width="18" height="21" viewBox="0 0 26 30">
            <path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 16.8 L20 16.8 Z" fill="white" stroke="#081020" stroke-width="2" />
        </svg>
    </div>
</template>

<style scoped>
.scene-menu {
    transform-origin: 0 0;
    animation: menu-pop 4.5s ease-out infinite;
}

.scene-item-first {
    animation: hover-first 4.5s infinite;
}

.scene-item-second {
    animation: hover-second 4.5s infinite;
}

.scene-cursor {
    animation: cursor-move 4.5s ease-in-out infinite;
}

@keyframes menu-pop {
    0%, 10% { opacity: 0; transform: scale(0.85); }
    16%, 92% { opacity: 1; transform: scale(1); }
    100% { opacity: 0; transform: scale(1); }
}

@keyframes hover-first {
    0%, 28% { background: transparent; }
    32%, 58% { background: rgb(231 239 254); }
    62%, 100% { background: transparent; }
}

@keyframes hover-second {
    0%, 62% { background: transparent; }
    66%, 92% { background: rgb(254 226 226); }
    100% { background: transparent; }
}

@keyframes cursor-move {
    0%, 16% { transform: translate(0, 0); }
    32%, 58% { transform: translate(40px, 42px); }
    66%, 100% { transform: translate(40px, 84px); }
}
</style>
