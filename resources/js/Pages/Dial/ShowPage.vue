<template>
    <div class="flex min-h-[60vh] items-center justify-center p-4">
        <BoxComponent class="w-full max-w-sm text-center">
            <p class="text-sm text-gray-500 dark:text-slate-400">Klant bellen</p>
            <p class="mt-1 text-2xl font-semibold tracking-wide text-gray-900 dark:text-slate-100">{{ number }}</p>

            <a :href="'tel:' + number"
                class="mt-6 flex w-full items-center justify-center gap-2 rounded-lg bg-lavoro-blue px-4 py-3 text-base font-semibold text-white shadow-sm hover:bg-lavoro-blue/90">
                <PhoneIcon class="size-5" />
                Bellen
            </a>
        </BoxComponent>
    </div>
</template>

<script setup>
import { PhoneIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'

defineProps({
    number: { type: String, required: true },
})
</script>
