<template>
    <button v-if="openedByTheApp" type="button"
        class="fixed top-3 right-3 z-[1000] inline-flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-800 shadow-md ring-1 ring-black/10 hover:bg-gray-50"
        @click="close">
        <XMarkIcon class="h-5 w-5" />
        Sluiten
    </button>
</template>

<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline';

/**
 * The maps open in a window of their own, and some window managers give such
 * a window no title bar: nothing to close it with but the keyboard. This is
 * the way out. Only shown where the app opened the window, because a browser
 * closes no other.
 */
const openedByTheApp = window.opener !== null;

const close = () => window.close();
</script>
