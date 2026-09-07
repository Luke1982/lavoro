<template>
    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-3 mt-0 text-base font-semibold">Nieuwe reseller</h3>

        <form @submit.prevent="submit">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label for="reseller-name" class="mb-1 block font-semibold">Naam</label>
                    <input id="reseller-name" v-model="form.name" type="text" required
                        class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <p v-if="form.errors.name" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label for="reseller-email" class="mb-1 block font-semibold">E-mailadres</label>
                    <input id="reseller-email" v-model="form.email" type="email"
                        class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <p v-if="form.errors.email" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label for="reseller-commission" class="mb-1 block font-semibold">Commissie (%)</label>
                    <input id="reseller-commission" v-model.number="form.commission_percent" type="number" min="0" max="100"
                        class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <p v-if="form.errors.commission_percent" class="mt-1 text-sm font-semibold text-red-700">
                        {{ form.errors.commission_percent }}
                    </p>
                </div>
            </div>

            <button type="submit" :disabled="form.processing"
                class="mt-4 rounded-md bg-blue-700 px-4 py-2 text-white disabled:opacity-60">Toevoegen</button>
        </form>
    </section>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'

const form = useForm({ name: '', email: '', commission_percent: 10 })

const submit = () => form.post('/beheer/resellers', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
})
</script>
