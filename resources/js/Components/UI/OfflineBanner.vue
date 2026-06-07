<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="-translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-full opacity-0"
    >
        <div
            v-if="!is_online"
            class="fixed top-0 inset-x-0 z-50 flex items-center justify-center gap-2 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950 shadow-md"
        >
            <ExclamationTriangleIcon class="size-4 shrink-0" />
            Geen internetverbinding — gegevens worden verstuurd zodra u weer online bent.
        </div>
    </Transition>
</template>

<script setup>
import { ExclamationTriangleIcon } from '@heroicons/vue/24/solid';
import { useNetworkStatus } from '@/Composables/useNetworkStatus.js';

const { is_online } = useNetworkStatus();
</script>
