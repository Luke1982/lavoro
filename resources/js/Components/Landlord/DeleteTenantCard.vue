<template>
    <section class="mt-6 rounded-lg border border-red-300 bg-white p-5">
        <h3 class="mt-0 mb-2 text-base font-semibold text-red-700">Klant verwijderen</h3>

        <p class="mb-3 text-sm text-slate-500">
            Dit gooit de database van {{ tenant.name }} weg, het databaseaccount, de
            bestanden en de centrale rijen. Er is geen weg terug en er is geen
            prullenbak. Tik de naam letterlijk over om te bevestigen.
        </p>

        <form @submit.prevent="submit">
            <div class="grid items-end gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block font-semibold">Naam van de klant</label>
                    <input v-model="form.confirm_name" type="text" required autocomplete="off"
                        :placeholder="tenant.name"
                        class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <p v-if="form.errors.confirm_name" class="mt-1 font-semibold text-red-700">
                        {{ form.errors.confirm_name }}
                    </p>
                </div>
                <div>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-md bg-red-700 px-4 py-2 text-white disabled:opacity-60">
                        Definitief verwijderen
                    </button>
                </div>
            </div>
        </form>
    </section>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({ tenant: { type: Object, required: true } })

const form = useForm({ confirm_name: '' })

const submit = () => form.delete(`/beheer/tenants/${props.tenant.id}`, { preserveScroll: true })
</script>
