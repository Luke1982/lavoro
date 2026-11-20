<template>
    <div>
        <p v-if="blocked && canUpgrade && outdated.length"
            class="mb-3 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-slate-700/40 dark:text-slate-300">
            Sla eerst je wijzigingen op: bijwerken zet contracten op de opgeslagen versie {{ latestVersion }}, niet op wat nu op het
            canvas staat.
        </p>

        <div v-if="outdated.length" data-tour="formula.contracts.outdated"
            class="mb-4 flex flex-col gap-3 rounded-lg bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:bg-amber-900/30 dark:text-amber-200">
            <p>
                {{ outdated.length === 1 ? 'Eén lopend contract staat' : `${outdated.length} lopende contracten staan` }} nog op een
                oudere versie en {{ outdated.length === 1 ? 'houdt zijn' : 'houden hun' }} prijs tot je bijwerkt.
                Met versie {{ latestVersion }} gaat het totaal per periode van {{ nlCurrency(totals.now) }}
                naar {{ nlCurrency(totals.then) }}.
            </p>
            <button v-if="canUpgrade" type="button" :disabled="locked"
                class="flex-none rounded-md bg-amber-600 px-3 py-2 font-semibold text-white hover:opacity-90 disabled:opacity-50"
                @click="confirmAll = true">
                Alles bijwerken naar versie {{ latestVersion }}
            </button>
        </div>

        <p v-if="!shown.length" class="text-sm text-gray-500 dark:text-slate-400" data-tour="formula.contracts.list">
            Er is nog geen contract dat deze formule gebruikt. Kies hem op een contract of op een contractsjabloon.
        </p>

        <ul v-else class="divide-y divide-gray-100 dark:divide-slate-700" data-tour="formula.contracts.list">
            <li v-if="example" class="py-2 text-xs italic text-amber-700 dark:text-amber-400">Voorbeelden uit de rondleiding</li>
            <li v-for="contract in shown" :key="contract.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <div class="min-w-0">
                    <span v-if="example" class="font-medium">{{ contract.name }}</span>
                    <Link v-else :href="`/maintenancecontracts/${contract.id}`" class="font-medium hover:underline">
                        {{ contract.name }}
                    </Link>
                    <span class="ml-2 text-xs text-gray-500 dark:text-slate-400">versie {{ contract.version }}</span>
                    <BadgeComponent v-if="contract.ended" color="gray" :has-dot="false" class="ml-2">afgelopen</BadgeComponent>
                </div>
                <div class="flex items-center gap-3">
                    <span class="tabular-nums text-gray-700 dark:text-slate-200">{{ nlCurrency(contract.price) }}</span>
                    <template v-if="contract.new_price !== null">
                        <span class="text-gray-400">→</span>
                        <span class="tabular-nums font-semibold"
                            :class="contract.new_price > contract.price ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-400'">
                            {{ nlCurrency(contract.new_price) }}
                        </span>
                        <button v-if="canUpgrade" type="button" :disabled="locked"
                            class="text-sm font-medium text-lavoro-blue hover:underline disabled:opacity-50 disabled:hover:no-underline dark:text-blue-400"
                            @click="upgrade([contract.id])">
                            Bijwerken
                        </button>
                    </template>
                </div>
            </li>
        </ul>

        <ModalDialog v-model:open="confirmAll" title="Contracten bijwerken" max-width-class="sm:max-w-md">
            <p class="text-sm text-gray-600 dark:text-slate-300">
                {{ outdated.length === 1 ? 'Eén contract gaat' : `${outdated.length} contracten gaan` }} naar versie {{ latestVersion }}.
                {{ outdated.length === 1 ? 'Het kost' : 'Samen kosten ze' }} dan {{ nlCurrency(totals.then) }} per periode in plaats
                van {{ nlCurrency(totals.now) }}. De nieuwe prijs komt op de tijdlijn van elk contract.
            </p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button"
                        class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                        @click="confirmAll = false">
                        Annuleren
                    </button>
                    <button type="button"
                        class="rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white hover:opacity-90"
                        @click="upgrade(null)">
                        Bijwerken
                    </button>
                </div>
            </template>
        </ModalDialog>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import { useTour } from '@/Composables/useTour'
import { nlCurrency } from '@/Utilities/Utilities'

/**
 * The contracts priced by a formula. Those still running on an older version
 * keep their price until they are moved on; this says which, and what they would
 * cost then. An ended contract keeps the price it ended with.
 */
const props = defineProps({
    formulaId: { type: Number, required: true },
    contracts: { type: Array, default: () => [] },
    latestVersion: { type: Number, required: true },
    canUpgrade: { type: Boolean, default: false },
    /** The canvas has changes not saved yet: moving contracts on now would not take them along. */
    blocked: { type: Boolean, default: false },
})

const confirmAll = ref(false)

/** Shown by the rondleiding while no contract uses the formula yet, and never sent anywhere. */
const example = ref(false)
const exampleContracts = computed(() => [
    { id: 'voorbeeld-1', name: 'Bakkerij De Korenaar', version: props.latestVersion, price: 420, new_price: null, ended: false },
    { id: 'voorbeeld-2', name: 'Hotel Zeezicht', version: Math.max(1, props.latestVersion - 1), price: 370, new_price: 395, ended: false },
    { id: 'voorbeeld-3', name: 'Garage Van Dijk', version: Math.max(1, props.latestVersion - 1), price: 180, new_price: null, ended: true },
])

const shown = computed(() => (example.value ? exampleContracts.value : props.contracts))
const locked = computed(() => props.blocked || example.value)

const outdated = computed(() => shown.value.filter(contract => contract.new_price !== null))

const totals = computed(() => ({
    now: outdated.value.reduce((sum, contract) => sum + contract.price, 0),
    then: outdated.value.reduce((sum, contract) => sum + contract.new_price, 0),
}))

function upgrade(contractIds) {
    confirmAll.value = false
    if (locked.value) return

    router.post(`/priceformulas/${props.formulaId}/upgrade-contracts`, { contract_ids: contractIds }, {
        preserveScroll: true,
        preserveState: true,
    })
}

useTour('prijsformule', {
    showExampleContracts: () => {
        if (props.contracts.length) return null
        example.value = true

        return () => { example.value = false }
    },
})
</script>
