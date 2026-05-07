<template>
    <div class="space-y-6">
        <div
            class="p-4 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800 dark:text-white">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Technisch beheer</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Beheerfuncties voor technisch gebruik</p>
        </div>

        <div
            class="p-6 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Test e-mail versturen</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400 mb-4">
                Stuur een test e-mail om de mailconfiguratie te controleren.
            </p>

            <form @submit.prevent="submit" class="space-y-4 max-w-md">
                <div>
                    <label for="email"
                        class="block text-sm font-medium text-gray-900 dark:text-white">E-mailadres</label>
                    <div class="mt-1">
                        <input id="email" v-model="form.email" type="email" autocomplete="off"
                            placeholder="naam@voorbeeld.nl"
                            class="block w-full rounded-md border-0 py-1.5 pl-2 text-gray-900 dark:text-white dark:bg-slate-900 ring-1 ring-inset sm:text-sm sm:leading-6"
                            :class="form.errors.email
                                ? 'ring-red-300 focus:ring-red-500 placeholder:text-red-300'
                                : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset'" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>
                </div>

                <button type="submit" :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-600 disabled:opacity-50">
                    <span v-if="form.processing">Versturen...</span>
                    <span v-else>Test e-mail versturen</span>
                </button>
            </form>

            <div v-if="lastError"
                class="mt-4 rounded-md bg-red-50 dark:bg-red-900/20 p-4 ring-1 ring-red-300 dark:ring-red-700">
                <p class="text-sm font-semibold text-red-800 dark:text-red-400">Foutmelding:</p>
                <p class="mt-1 text-sm text-red-700 dark:text-red-300 break-all whitespace-pre-wrap">{{ lastError }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'

const page = usePage()
const form = useForm({ email: '' })
const lastError = ref(null)

watch(() => page.props.flash.error, (val) => {
    if (val) lastError.value = val
})

watch(() => page.props.flash.success, (val) => {
    if (val) lastError.value = null
})

const submit = () => {
    lastError.value = null
    form.post('/technical-management/test-mail')
}
</script>
