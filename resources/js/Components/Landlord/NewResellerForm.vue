<template>
    <PanelSection title="Nieuwe reseller">

        <form @submit.prevent="submit">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label for="reseller-name" class="mb-1 block font-semibold">Naam</label>
                    <input id="reseller-name" v-model="form.name" type="text" required
                        class="panel-field w-full">
                    <p v-if="form.errors.name" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label for="reseller-email" class="mb-1 block font-semibold">E-mailadres</label>
                    <input id="reseller-email" v-model="form.email" type="email"
                        class="panel-field w-full">
                    <p v-if="form.errors.email" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label for="reseller-commission" class="mb-1 block font-semibold">Commissie (%)</label>
                    <input id="reseller-commission" v-model.number="form.commission_percent" type="number" min="0" max="100"
                        class="panel-field w-full">
                    <p v-if="form.errors.commission_percent" class="mt-1 text-sm font-semibold text-red-700">
                        {{ form.errors.commission_percent }}
                    </p>
                </div>
            </div>

            <button type="submit" :disabled="form.processing"
                class="panel-button mt-4">Toevoegen</button>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { useForm } from '@inertiajs/vue3'

const form = useForm({ name: '', email: '', commission_percent: 10 })

const submit = () => form.post('/beheer/resellers', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
})
</script>
