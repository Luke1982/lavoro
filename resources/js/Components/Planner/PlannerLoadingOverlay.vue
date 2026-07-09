<template>
    <div class="flex-1 min-h-0 relative">
        <slot />

        <!-- Sibling of the slotted scroll container (not a descendant), so it stays centered
             on the visible viewport no matter how far the content has been scrolled. -->
        <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
            <div v-if="loading" class="absolute inset-0 z-50 flex items-center justify-center pointer-events-none">
                <div class="flex flex-col items-center gap-3 bg-white/90 dark:bg-slate-900/90 backdrop-blur-sm rounded-xl px-6 py-5 shadow-lg text-sm font-medium text-gray-600 dark:text-slate-300">
                    <svg class="animate-spin size-6 shrink-0 text-lavoro-blue" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                    Afspraken worden geladen…
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
defineProps({
    loading: { type: Boolean, default: false },
})
</script>
