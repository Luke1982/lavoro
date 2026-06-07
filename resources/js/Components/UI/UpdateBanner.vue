<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div
            v-if="update_url"
            class="fixed bottom-0 inset-x-0 z-50 flex items-center justify-between gap-3 bg-blue-600 px-4 py-3 shadow-lg"
        >
            <div class="flex items-center gap-2 text-white">
                <ArrowDownCircleIcon class="size-5 shrink-0" />
                <span class="text-sm font-medium">Nieuwe versie beschikbaar</span>
            </div>
            <div class="flex items-center gap-2">
                <a
                    :href="update_url"
                    class="rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-blue-600 shadow-sm"
                >
                    Updaten
                </a>
                <button @click="update_url = null" class="text-white/70 hover:text-white">
                    <XMarkIcon class="size-5" />
                </button>
            </div>
        </div>
    </Transition>
</template>

<script setup>
import { ArrowDownCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { useAppUpdate } from '@/Composables/useAppUpdate.js';

const { update_url } = useAppUpdate();
</script>
