<template>
    <div class="p-6 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Instellingen</h1>

        <section>
            <h2 class="text-base font-semibold text-gray-900 mb-4">Locatie tracking</h2>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="flex gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Starttijd</label>
                        <input
                            type="time"
                            v-model="form.start"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.start" class="mt-1 text-xs text-red-600">{{ form.errors.start }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Eindtijd</label>
                        <input
                            type="time"
                            v-model="form.end"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.end" class="mt-1 text-xs text-red-600">{{ form.errors.end }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Dagen</label>
                    <div class="flex gap-2 flex-wrap">
                        <button
                            v-for="day in DAYS"
                            :key="day.value"
                            type="button"
                            @click="toggleDay(day.value)"
                            :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                form.days.includes(day.value)
                                    ? 'bg-indigo-600 border-indigo-600 text-white'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                            ]"
                        >
                            {{ day.label }}
                        </button>
                    </div>
                    <p v-if="form.errors.days" class="mt-1 text-xs text-red-600">{{ form.errors.days }}</p>
                </div>

                <div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Opslaan
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    locationTracking: { type: Object, required: true },
})

const DAYS = [
    { value: 1, label: 'Ma' },
    { value: 2, label: 'Di' },
    { value: 3, label: 'Wo' },
    { value: 4, label: 'Do' },
    { value: 5, label: 'Vr' },
    { value: 6, label: 'Za' },
    { value: 7, label: 'Zo' },
]

const form = useForm({
    start: props.locationTracking.start,
    end:   props.locationTracking.end,
    days:  [...props.locationTracking.days],
})

function toggleDay(value) {
    const index = form.days.indexOf(value)
    if (index === -1) {
        form.days.push(value)
    } else {
        form.days.splice(index, 1)
    }
}

function submit() {
    form.put('/admin/settings/location-tracking')
}
</script>
