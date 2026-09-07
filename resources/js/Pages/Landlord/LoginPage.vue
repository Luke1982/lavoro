<template>
    <Head title="Inloggen" />

    <div class="mx-auto mt-10 max-w-md rounded-lg border border-slate-200 bg-white p-6">
        <h2 class="mb-4 mt-0 text-lg font-semibold">Inloggen</h2>

        <form @submit.prevent="submit">
            <label for="email" class="mb-1 block font-semibold">E-mailadres</label>
            <input id="email" v-model="form.email" type="email" autofocus required
                class="w-full rounded-md border border-slate-300 px-3 py-2">
            <p v-if="form.errors.email" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.email }}</p>

            <label for="password" class="mb-1 mt-3 block font-semibold">Wachtwoord</label>
            <input id="password" v-model="form.password" type="password" required
                class="w-full rounded-md border border-slate-300 px-3 py-2">
            <p v-if="form.errors.password" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.password }}</p>

            <button type="submit" :disabled="form.processing"
                class="mt-4 rounded-md bg-blue-700 px-4 py-2 text-white disabled:opacity-60">Inloggen</button>
        </form>
    </div>
</template>

<script setup>
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '' })

const submit = () => form.post('/beheer/login', { onFinish: () => form.reset('password') })
</script>
