<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-100 dark:bg-slate-800 px-4">
        <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-8 text-center shadow-sm ring-1 ring-slate-200 dark:ring-slate-700">
            <img v-if="company?.logo_url" :src="company.logo_url" :alt="company.name" class="mx-auto h-9 w-auto" />
            <span v-else class="text-lg font-bold text-slate-800 dark:text-slate-200">{{ company?.name || 'Service' }}</span>

            <CheckCircleIcon class="mx-auto mt-6 size-10 text-green-500" />

            <h1 class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">{{ thanks || 'Al beantwoord' }}</h1>
            <p v-if="!thanks" class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Op deze link is op {{ answeredOn }} gereageerd ({{ purpose || 'een vraag' }}), dus u hoeft niets meer te doen.
                Wilt u toch nog iets laten weten, neem dan contact met ons op.
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import EmptyLayout from '@/Layouts/EmptyLayout.vue'

/**
 * Where a link that takes one answer lands once it has had it. Right after the
 * answer, the thanks the screen behind the link sent along is the heading.
 */
const props = defineProps({
    purpose: { type: String, default: null },
    answered_on: { type: String, default: null },
})

const page = usePage()
const company = computed(() => page.props.company)
const thanks = computed(() => page.props.flash?.success ?? null)

const answeredOn = computed(() => (props.answered_on
    ? new Date(props.answered_on).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })
    : 'een eerder moment'))

defineOptions({ layout: EmptyLayout })
</script>
