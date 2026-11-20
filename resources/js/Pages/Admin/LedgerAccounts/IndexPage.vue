<template>
    <div class="space-y-6 max-w-4xl">
        <div class="p-4 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-700">
            <Link href="/integrations" class="text-xs font-semibold text-lavoro-blue dark:text-blue-400 hover:underline">
                ← Koppelingen
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">Grootboekrekeningen</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                De omzetrekeningen uit de boekhouding. Kies er bij een materiaal of product een, dan komt een werkbonregel
                met dat materiaal op die rekening. Zonder keuze boekt de boekhouding de regel zoals ze dat zelf doet.
            </p>
            <p v-if="!inUse"
                class="mt-3 rounded-md bg-amber-50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-300 ring-1 ring-amber-200 dark:ring-amber-800">
                Geen koppeling haalt of stuurt grootboekrekeningen. Zet dat aan bij een koppeling; tot dan staat de keuze
                niet bij materialen en producten.
            </p>
            <p v-else-if="sendsTo.length" class="mt-3 text-sm text-gray-500 dark:text-slate-400">
                Nieuwe en gewijzigde rekeningen gaan meteen naar {{ sendsTo.join(' en ') }}.
            </p>
            <div v-if="pullSources.length" class="mt-3 flex flex-wrap gap-2">
                <button v-for="source in pullSources" :key="source.id" type="button" :disabled="pulling !== null"
                    @click="pullFrom(source)"
                    class="px-3 py-2 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90 disabled:bg-gray-400">
                    {{ pulling === source.id ? 'Bezig...' : 'Ophalen uit ' + source.label }}
                </button>
            </div>
        </div>

        <form class="p-4 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-700"
            @submit.prevent="add">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Nieuwe grootboekrekening</h2>
            <div class="grid grid-cols-1 sm:grid-cols-[10rem_1fr_auto] gap-3 items-start">
                <TextInput v-model="newForm.code" label="Nummer" placeholder="8000"
                    :has-error="!!newForm.errors.code" :error-message="newForm.errors.code || ''" />
                <TextInput v-model="newForm.name" label="Omschrijving" placeholder="Omzet"
                    :has-error="!!newForm.errors.name" :error-message="newForm.errors.name || ''" />
                <button type="submit" :disabled="newForm.processing"
                    class="sm:mt-8 inline-flex items-center justify-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    Toevoegen
                </button>
            </div>
        </form>

        <div class="bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-700">
            <div v-if="ledgerAccounts.length" class="divide-y divide-gray-100 dark:divide-slate-700">
                <div class="hidden md:grid grid-cols-12 gap-4 p-4 text-sm font-bold bg-lavoro-lightgray dark:bg-slate-800/50 rounded-t-md">
                    <div class="col-span-2">Nummer</div>
                    <div class="col-span-4">Omschrijving</div>
                    <div class="col-span-3">Gebruikt bij</div>
                    <div class="col-span-2">Gekoppeld met</div>
                    <div class="col-span-1"></div>
                </div>
                <div v-for="account in ledgerAccounts" :key="account.id"
                    class="grid grid-cols-1 md:grid-cols-12 gap-2 md:gap-4 p-4 text-sm items-center">
                    <div class="md:col-span-2">
                        <EditableTextField :model-value="account.code" :decoration="false"
                            :error="editing === account.id ? editForm.errors.code : ''"
                            @update="(value) => save(account, 'code', value)">
                            <template #display>{{ account.code || '—' }}</template>
                        </EditableTextField>
                    </div>
                    <div class="md:col-span-4">
                        <EditableTextField :model-value="account.name" :decoration="false"
                            :error="editing === account.id ? editForm.errors.name : ''"
                            @update="(value) => save(account, 'name', value)">
                            <template #display>{{ account.name }}</template>
                        </EditableTextField>
                    </div>
                    <div class="md:col-span-3 text-gray-600 dark:text-slate-400">{{ usedBy(account) }}</div>
                    <div class="md:col-span-2 flex flex-wrap gap-1">
                        <span v-for="system in account.linked" :key="system"
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-gray-300 text-gray-600 dark:ring-slate-600 dark:text-slate-300">
                            {{ system }}
                        </span>
                    </div>
                    <div class="md:col-span-1 flex md:justify-end">
                        <button type="button" :title="'Grootboekrekening ' + account.label + ' verwijderen'" @click="remove(account)">
                            <TrashIcon class="h-5 w-5 text-red-500" />
                        </button>
                    </div>
                </div>
            </div>
            <p v-else class="p-6 text-center text-sm text-gray-400">
                Nog geen grootboekrekeningen. Voeg er een toe, of haal ze op uit de boekhouding.
            </p>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { TrashIcon } from '@lucide/vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import TextInput from '@/Components/UI/TextInput.vue'

defineProps({
    ledgerAccounts: { type: Array, default: () => [] },
    inUse: { type: Boolean, default: false },
    pullSources: { type: Array, default: () => [] },
    sendsTo: { type: Array, default: () => [] },
})

const usedBy = (account) => {
    const parts = []
    if (account.materials_count) parts.push(account.materials_count + (account.materials_count === 1 ? ' materiaal' : ' materialen'))
    if (account.products_count) parts.push(account.products_count + (account.products_count === 1 ? ' product' : ' producten'))
    return parts.length ? parts.join(', ') : '—'
}

const newForm = useForm({ code: '', name: '' })
const add = () => newForm.post('/integrations/ledger-accounts', { preserveScroll: true, onSuccess: () => newForm.reset() })

const editing = ref(null)
const editForm = useForm({ code: '', name: '' })
const save = (account, field, value) => {
    editing.value = account.id
    editForm.code = account.code
    editForm.name = account.name
    editForm[field] = value
    editForm.put(`/integrations/ledger-accounts/${account.id}`, { preserveScroll: true })
}

const remove = (account) => {
    const systems = account.linked.join(' en ')
    const message = 'Grootboekrekening ' + account.label + ' verwijderen?'
        + (systems ? ' In ' + systems + ' blijft hij staan, en haalt Lavoro daar grootboekrekeningen op, dan komt hij de volgende keer terug.' : '')
    if (!confirm(message)) return
    router.delete(`/integrations/ledger-accounts/${account.id}`, { preserveScroll: true })
}

const pulling = ref(null)
const pullForm = useForm({})
const pullFrom = (source) => {
    pulling.value = source.id
    pullForm.post(`/integrations/${source.id}/pull/ledger_accounts`, {
        preserveScroll: true,
        onFinish: () => { pulling.value = null },
    })
}
</script>
