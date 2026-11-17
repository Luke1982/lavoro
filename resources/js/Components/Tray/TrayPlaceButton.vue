<template>
    <button v-if="currentRecord" type="button" :disabled="currentRecord.onTray"
        @click="placeCurrentOnTray($event.currentTarget)"
        class="group hidden items-center gap-1.5 rounded-md bg-sidebar-bg px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:cursor-default disabled:opacity-60 lg:inline-flex">
        <InboxIcon class="size-4 text-sidebar-indicator transition-transform duration-300 group-hover:translate-y-0.5" aria-hidden="true" />
        {{ currentRecord.onTray ? 'Ligt op het bakje' : 'Op het bakje' }}
    </button>
</template>

<script setup>
import { InboxIcon } from '@lucide/vue'
import { useTray, placeCurrentOnTray } from '@/Composables/useTray.js'

/**
 * Puts the record this page is about on the bakje, from the top of the page on
 * a computer. On a phone the same lives in the bakje itself, which is where the
 * room is. The page says which record that is with useTrayRecord.
 */
const { currentRecord } = useTray()
</script>
