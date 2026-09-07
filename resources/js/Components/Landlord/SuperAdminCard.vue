<template>
    <PanelSection title="Superbeheerders">

        <template #description>

            Accounts van MajorLabel binnen deze klant. Mogen alles, langs elke

            rechtencontrole heen. De klant ziet deze rol nergens en kan hem niet

            toekennen.

        </template>


        <table v-if="superadmins.length" class="w-full text-left">
            <tr v-for="admin in superadmins" :key="admin.id" class="border-t border-slate-200">
                <td class="py-2">{{ admin.name }} <span class="text-slate-500">{{ admin.email }}</span></td>
                <td class="w-28 py-2 text-right">
                    <button type="button" class="text-blue-700 underline" @click="remove(admin)">verwijderen</button>
                </td>
            </tr>
        </table>
        <p v-else class="text-sm text-slate-500">Nog geen superbeheerder voor deze klant.</p>

        <form class="mt-3" @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <input v-model="form.email" type="email" required placeholder="e-mailadres"
                        class="panel-field w-full">
                    <p v-if="form.errors.email" class="mt-1 font-semibold text-red-700">{{ form.errors.email }}</p>
                </div>
                <input v-model="form.password" type="text" placeholder="wachtwoord (leeg = genereren)"
                    class="panel-field w-full">
            </div>
            <p class="mt-3">
                <button type="submit" :disabled="form.processing"
                    class="panel-button">
                    Superbeheerder toevoegen
                </button>
            </p>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
    tenant: { type: Object, required: true },
    superadmins: { type: Array, required: true },
})

const form = useForm({ email: '', password: '' })

const submit = () => form.post(`/beheer/${props.tenant.id}/superbeheerder`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
})

const remove = (admin) => router.delete(`/beheer/${props.tenant.id}/superbeheerder/${admin.id}`, {
    preserveScroll: true,
})
</script>
