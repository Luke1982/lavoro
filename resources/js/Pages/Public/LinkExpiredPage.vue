<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
            <img v-if="company?.logo_url" :src="company.logo_url" :alt="company.name" class="mx-auto h-9 w-auto" />
            <span v-else class="text-lg font-bold text-slate-800">{{ company?.name || 'Service' }}</span>

            <ClockIcon class="mx-auto mt-6 size-10 text-amber-500" />

            <h1 class="mt-4 text-lg font-bold text-slate-900">Deze link is verlopen</h1>
            <p class="mt-2 text-sm text-slate-500">
                {{ purpose || 'Deze link' }} was geldig tot {{ expiredOn }}.
                Neem contact met ons op, dan sturen wij u een nieuwe.
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { ClockIcon } from '@heroicons/vue/24/outline'
import EmptyLayout from '@/Layouts/EmptyLayout.vue'

const props = defineProps({
    purpose: { type: String, default: null },
    expired_on: { type: String, default: null },
})

const page = usePage()
const company = computed(() => page.props.company)

const expiredOn = computed(() => (props.expired_on
    ? new Date(props.expired_on).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })
    : 'kort geleden'))

defineOptions({ layout: EmptyLayout })
</script>
