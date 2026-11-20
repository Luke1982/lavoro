<template>
    <div class="space-y-4 text-sm">
        <SegmentedToggle v-if="testContracts.length" v-model="bench.mode" :options="modes" />

        <template v-if="bench.mode === 'contract'">
            <ComboBox :options="testContracts" v-model="bench.contract_id" label="Contract"
                placeholder="Kies een contract om op te proberen" data-tour="formula.bench.contract" />
            <p class="text-xs text-gray-500 dark:text-slate-400">
                Rekent met de echte machines, het prijsinterval en de ingevulde waarden van dit contract. Er wordt niets opgeslagen.
            </p>
        </template>

        <template v-else>
            <TrialMachines v-model="bench.sample" :picker-props="pickerProps" data-tour="formula.bench.machines" />
            <div class="grid grid-cols-2 gap-2" data-tour="formula.bench.context">
                <label class="block">
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Prijsinterval</span>
                    <select v-model="bench.price_interval" :class="selectClass">
                        <option v-for="option in intervalOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300">Onderhoud</span>
                    <select v-model="bench.frequency" :class="selectClass">
                        <option v-for="option in intervalOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </label>
            </div>
        </template>

        <div v-if="inputBlocks.length" class="space-y-2" data-tour="formula.bench.inputs">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-slate-400">Invoer per contract</p>
            <TextInput v-for="input in inputBlocks" :key="input.id" :id="'bench-input-' + input.id" :model-value="bench.inputs[input.id] ?? ''" type="number"
                step="any" :label="input.name" :placeholder="placeholderFor(input)"
                @update:model-value="v => setInput(input.id, v)" />
        </div>

        <div class="rounded-lg bg-gray-50 p-3 dark:bg-slate-900/60" data-tour="formula.bench.result">
            <p v-if="bench.mode === 'contract' && !bench.contract_id" class="text-gray-500 dark:text-slate-400">
                Kies een contract om de formule op uit te rekenen.
            </p>
            <p v-else-if="error" class="text-sm text-amber-700 dark:text-amber-400">{{ error }}</p>
            <template v-else-if="results">
                <p class="flex justify-between font-bold text-gray-900 dark:text-white">
                    <span>Prijs per periode</span>
                    <span class="tabular-nums">{{ results.periodic === null ? 'geen blok' : nlCurrency(results.periodic) }}</span>
                </p>
                <p class="mt-1 flex justify-between text-gray-700 dark:text-slate-300">
                    <span>Eenmalige kosten</span>
                    <span class="tabular-nums">{{ results.one_time === null ? 'geen blok' : nlCurrency(results.one_time) }}</span>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    Over {{ machineCount(results.machine_count) }}.
                </p>
                <UnpricedMachines v-if="results.unpriced.length" :machines="results.unpriced" data-tour="formula.bench.unpriced" />
            </template>
            <p v-else class="text-gray-500 dark:text-slate-400">Bezig met rekenen…</p>
        </div>
    </div>
</template>

<script setup>
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import TrialMachines from '@/Components/PriceFormulas/TrialMachines.vue'
import UnpricedMachines from '@/Components/PriceFormulas/UnpricedMachines.vue'
import { nlCurrency } from '@/Utilities/Utilities'
import { SELECT_CLASS, formatNumber, machineCount } from './formulaEditor'

/**
 * Where a formula is tried out while it is built: on a made-up customer or on a
 * real contract. What it comes to shows here and on every block.
 */
const bench = defineModel({ type: Object, required: true })

const props = defineProps({
    /** The blocks of the formula that ask for a number per contract. */
    inputBlocks: { type: Array, default: () => [] },
    testContracts: { type: Array, default: () => [] },
    intervalOptions: { type: Array, default: () => [] },
    pickerProps: { type: Object, default: () => ({}) },
    results: { type: Object, default: null },
    error: { type: String, default: null },
})

const modes = [
    { value: 'sample', label: 'Voorbeeldklant' },
    { value: 'contract', label: 'Bestaand contract' },
]

const selectClass = 'mt-1 ' + SELECT_CLASS

/** On a contract, what that contract has filled in; otherwise the block's own standard value. */
function placeholderFor(input) {
    const onContract = bench.value.mode === 'contract' ? props.results?.inputs?.[input.id] : undefined

    return onContract !== undefined
        ? `Op dit contract ${formatNumber(onContract)}`
        : `Standaard ${formatNumber(input.default)}`
}

function setInput(id, value) {
    const inputs = { ...bench.value.inputs }
    if (value === '' || value === null) delete inputs[id]
    else inputs[id] = Number(value)
    bench.value.inputs = inputs
}
</script>
