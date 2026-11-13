<template>
    <PillButton v-if="allowed" @click="openAssistant" :title="'Vraag de assistent (' + shortcut + ')'"
        class="text-indigo-600 hover:bg-indigo-50 dark:hover:bg-slate-700">
        <SparklesIcon class="size-5" />
        <span class="hidden sm:inline text-xs text-slate-400 font-normal">{{ shortcut }}</span>
    </PillButton>
</template>

<script setup>
import { computed } from 'vue'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import { usePage } from '@inertiajs/vue3'
import PillButton from '@/Components/UI/PillButton.vue'
import { assistantShortcutLabel, openAssistant } from '@/Composables/useAssistant.js'

/**
 * The verdict comes from the backend. The rule has an exception in it — being an
 * admin does not grant the assistant — and deciding that here as well would be a
 * second implementation, free to drift from the policy that actually guards the
 * endpoint.
 */
const allowed = computed(() => usePage().props?.auth?.can?.use_assistant === true)
const shortcut = assistantShortcutLabel()
</script>
