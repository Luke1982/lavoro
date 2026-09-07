<template>
    <PanelSection title="Wachtwoorden om door te geven" v-if="passwords.length" class="mt-6">
        <template #description>
            Van een nieuwe tenant. Geef het door en wis het daarna; het staat hier
            leesbaar zolang het er staat.
        </template>


        <table class="w-full text-left">
            <tr v-for="password in passwords" :key="password.id" class="border-t border-slate-200">
                <td class="px-3 py-2.5">
                    <strong>{{ password.name }}</strong><br>
                    {{ password.email }} &middot;
                    <code class="rounded bg-slate-100 px-1.5 py-0.5">{{ password.password }}</code>
                </td>
                <td class="w-28 px-3 py-2.5 text-right">
                    <button type="button" class="text-blue-700 underline"
                        @click="forget(password)">wissen</button>
                </td>
            </tr>
        </table>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { router } from '@inertiajs/vue3'

defineProps({ passwords: { type: Array, required: true } })

const forget = (password) => router.delete(`/beheer/aanvraag/${password.id}/wachtwoord`, {
    preserveScroll: true,
})
</script>
