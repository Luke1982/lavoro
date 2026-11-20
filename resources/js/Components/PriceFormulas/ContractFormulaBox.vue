<template>
    <div class="space-y-4 text-sm">
        <p v-if="example" class="text-xs italic text-amber-700 dark:text-amber-400">
            Voorbeeld uit de rondleiding: zo ziet het eruit als dit contract een prijsformule heeft.
        </p>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-72" data-tour="contract.formula.pick">
                <ComboBox :options="example ? [{ id: 'example', name: priceFormula.name }] : priceFormulas"
                    :model-value="example ? 'example' : priceFormula?.formula_id ?? null" label="Prijsformule"
                    placeholder="Vaste prijs, geen formule" :disabled="!canUpdate || example"
                    :has-error="Boolean(form.errors.price_formula_id)" :error-message="form.errors.price_formula_id"
                    @update:model-value="pick" />
            </div>
            <div v-if="priceFormula" class="flex items-end gap-3" data-tour="contract.formula.version">
                <BadgeComponent color="blue" :has-dot="false">Versie {{ priceFormula.version }}</BadgeComponent>
                <span v-if="canReadFormulas && example" class="pb-1 font-medium text-lavoro-blue opacity-60 dark:text-blue-400">
                    Bekijk de berekening
                </span>
                <Link v-else-if="canReadFormulas" :href="`/priceformulas/${priceFormula.formula_id}?contract=${contractId}`"
                    class="pb-1 font-medium text-lavoro-blue hover:underline dark:text-blue-400">
                    Bekijk de berekening
                </Link>
            </div>
        </div>

        <p v-if="!priceFormula" class="text-gray-500 dark:text-slate-400">
            De prijs hierboven is met de hand ingevuld. Kies een formule om de prijs te laten berekenen uit de machines op dit contract.
        </p>

        <template v-else>
            <div v-if="priceFormula.newer" data-tour="contract.formula.newer"
                class="flex flex-col gap-3 rounded-lg bg-amber-50 p-3 text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:bg-amber-900/30 dark:text-amber-200">
                <p>
                    Er is een nieuwere versie van {{ priceFormula.name }} (versie {{ priceFormula.newer.version }}).
                    Daarmee wordt de prijs {{ nlCurrency(priceFormula.newer.result.periodic ?? 0) }} per periode
                    (nu {{ nlCurrency(priceFormula.result.periodic ?? 0) }})<template
                        v-if="priceFormula.newer.result.one_time !== priceFormula.result.one_time">, eenmalig
                        {{ priceFormula.newer.result.one_time === null ? 'niets' : nlCurrency(priceFormula.newer.result.one_time) }}</template>.
                </p>
                <button v-if="canUpdate" type="button" :disabled="example || form.processing"
                    class="flex-none rounded-md bg-amber-600 px-3 py-2 font-semibold text-white hover:opacity-90 disabled:opacity-60"
                    @click="upgrade">
                    Bijwerken naar versie {{ priceFormula.newer.version }}
                </button>
            </div>

            <div v-if="priceFormula.inputs.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-tour="contract.formula.inputs">
                <TextInput v-for="input in priceFormula.inputs" :key="input.id" v-model="inputs[input.id]" type="number"
                    step="any" :label="input.name" :placeholder="`Standaard ${formatNumber(input.default)}`"
                    :disabled="!canUpdate || example" :has-error="Boolean(form.errors[`inputs.${input.id}`])"
                    :error-message="form.errors[`inputs.${input.id}`]" />
                <div v-if="inputsChanged" class="flex items-end gap-2 sm:col-span-2">
                    <button type="button"
                        class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 dark:text-white dark:ring-slate-600"
                        @click="cancelInputs">
                        Annuleren
                    </button>
                    <button type="button" :disabled="form.processing"
                        class="rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60"
                        @click="saveInputs">
                        Opslaan en opnieuw berekenen
                    </button>
                </div>
            </div>

            <div v-if="drifted" class="flex flex-col gap-3 rounded-lg bg-sky-50 p-3 text-sky-900 sm:flex-row sm:items-center sm:justify-between dark:bg-sky-900/30 dark:text-sky-200">
                <p>
                    De formule komt nu op {{ nlCurrency(priceFormula.result.periodic ?? 0) }} per periode, maar het contract staat op
                    {{ nlCurrency(storedPrice) }}. Dat gebeurt als er in de catalogus iets aan een machine is veranderd.
                </p>
                <button v-if="canUpdate" type="button" :disabled="form.processing"
                    class="flex-none rounded-md bg-sky-600 px-3 py-2 font-semibold text-white hover:opacity-90 disabled:opacity-60"
                    @click="recalculate">
                    Prijs opnieuw berekenen
                </button>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-slate-900/60" data-tour="contract.formula.result">
                <p class="flex justify-between font-bold text-gray-900 dark:text-white">
                    <span>Prijs per periode</span>
                    <span class="tabular-nums">{{ nlCurrency(priceFormula.result.periodic ?? 0) }}</span>
                </p>
                <p v-if="priceFormula.result.one_time !== null" class="mt-1 flex justify-between text-gray-700 dark:text-slate-300">
                    <span>Eenmalige kosten</span>
                    <span class="tabular-nums">{{ nlCurrency(priceFormula.result.one_time) }}</span>
                </p>
                <ul v-if="priceFormula.result.problems?.length"
                    class="mt-2 space-y-0.5 rounded-md bg-red-50 px-2 py-1.5 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-300">
                    <li v-for="problem in priceFormula.result.problems" :key="problem">{{ problem }}</li>
                </ul>
                <UnpricedMachines v-if="priceFormula.result.unpriced.length" :machines="priceFormula.result.unpriced" />
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import UnpricedMachines from '@/Components/PriceFormulas/UnpricedMachines.vue'
import { formatNumber } from '@/Components/PriceFormulas/formulaEditor'
import { nlCurrency } from '@/Utilities/Utilities'

/**
 * The price formula of one contract: which formula and version price it, what
 * the newest version would make of it, and the numbers the formula asks for on
 * this contract. Every change reprices the contract straight away.
 */
const props = defineProps({
    contractId: { type: Number, required: true },
    priceFormulas: { type: Array, default: () => [] },
    priceFormula: { type: Object, default: null },
    canUpdate: { type: Boolean, default: false },
    canReadFormulas: { type: Boolean, default: false },
    /** Shown by the rondleiding on a contract without a formula: nothing in it can be changed. */
    example: { type: Boolean, default: false },
    /** The price the contract has now, which the formula's outcome is held against. */
    storedPrice: { type: Number, default: null },
    /** An ended contract keeps the price it ended with, whatever the formula says now. */
    ended: { type: Boolean, default: false },
})

const url = computed(() => `/maintenancecontracts/${props.contractId}/price-formula`)

const saved = () => Object.fromEntries((props.priceFormula?.inputs ?? []).map(input => [input.id, input.value ?? '']))
const inputs = reactive(saved())

/** What the person typed stays until the stored numbers change, so a refused value stays beside its error. */
watch(() => JSON.stringify(saved()), () => resetInputs())

const inputsChanged = computed(() => JSON.stringify(normalized(inputs)) !== JSON.stringify(normalized(saved())))

function normalized(values) {
    return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value === '' || value === null ? null : Number(value)]))
}

function resetInputs() {
    Object.keys(inputs).forEach(key => delete inputs[key])
    Object.assign(inputs, saved())
}

function cancelInputs() {
    resetInputs()
    form.clearErrors()
}

const form = useForm({})

function put(payload) {
    form.transform(() => payload).put(url.value, { preserveScroll: true, preserveState: true })
}

function pick(id) {
    put({ price_formula_id: id ?? null })
}

/**
 * The machines on the contract are what reprices it at once; a change in the
 * catalogue, such as another type or price for a model, is not. Then the
 * outcome and the stored price drift apart, and this says so.
 */
const drifted = computed(() => !props.example && !props.ended && props.storedPrice !== null
    && props.priceFormula?.result?.periodic != null
    && Math.abs(props.priceFormula.result.periodic - props.storedPrice) >= 0.005)

function recalculate() {
    put({ price_formula_id: props.priceFormula.formula_id })
}

function upgrade() {
    put({ price_formula_id: props.priceFormula.formula_id, to_latest: true })
}

function saveInputs() {
    put({ inputs: Object.fromEntries(Object.entries(normalized(inputs)).filter(([, value]) => value !== null)) })
}
</script>
