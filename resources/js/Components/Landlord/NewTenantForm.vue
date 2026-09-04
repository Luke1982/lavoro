<template>
    <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mt-0 text-base font-semibold">Nieuwe tenant</h3>

        <form @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block font-semibold" for="name">Bedrijfsnaam</label>
                    <input id="name" v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <p class="mt-1 text-sm text-slate-500">De databasenaam volgt hieruit en ligt daarna vast.</p>
                    <p v-if="form.errors.name" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block font-semibold" for="email">E-mail van de eerste beheerder</label>
                    <input id="email" v-model="form.email" type="email" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <p v-if="form.errors.email" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.email }}</p>
                </div>
            </div>

            <label class="mb-1 mt-4 block font-semibold" for="package">Pakket</label>
            <select id="package" v-model="form.package_key" class="w-full rounded-md border border-slate-300 px-3 py-2">
                <option v-for="pack in packages" :key="pack.key" :value="pack.key">
                    {{ pack.name }} — {{ euro(pack.price_cents) }}
                </option>
            </select>

            <p class="mb-1 mt-4 font-semibold">Modules</p>
            <label v-for="module in modules" :key="module.key" class="flex items-center gap-2 py-1">
                <input v-model="form.modules" type="checkbox" :value="module.key">
                {{ module.name }} <span class="text-slate-500">{{ euro(module.price_cents) }}</span>
            </label>

            <p class="mt-4">
                <button type="submit" :disabled="form.processing"
                    class="rounded-md bg-blue-700 px-4 py-2 text-white disabled:opacity-60">
                    {{ form.processing ? 'Bezig…' : 'Aanmaken' }}
                </button>
            </p>

            <p class="text-sm text-slate-500">
                Het wachtwoord van de beheerder wordt gegenereerd en hierboven getoond
                zodra de tenant klaar is.
            </p>
        </form>
    </section>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    packages: { type: Array, required: true },
    modules: { type: Array, required: true },
})

const form = useForm({
    name: '',
    email: '',
    package_key: props.packages[0]?.key ?? 'starter',
    modules: [],
})

const euro = (cents) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' })
    .format((cents ?? 0) / 100)

/**
 * Het formulier blijft staan zoals het was als er iets misgaat: useForm houdt de
 * ingevoerde waarden vast en zet de meldingen bij de velden waar ze horen.
 */
const submit = () => form.post('/beheer/tenants', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
})
</script>
