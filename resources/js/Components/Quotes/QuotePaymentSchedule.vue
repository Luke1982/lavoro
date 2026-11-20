<template>
    <div>
        <div v-for="(term, index) in terms" :key="index" class="flex flex-wrap items-center gap-2 py-1.5">
            <input v-model.number="term.percent" type="number" step="any" min="0" max="100" :disabled="!editable"
                :class="[BOXED_FIELD, NO_SPIN, 'w-20 text-right']" aria-label="Percentage" @change="changed" />
            <span class="text-sm text-gray-500">%</span>
            <input v-model="term.description" :disabled="!editable" placeholder="Bijvoorbeeld: bij opdracht"
                :class="[BOXED_FIELD, 'min-w-40 flex-1']" aria-label="Wanneer" @change="changed" />
            <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-slate-300">
                <input v-model="term.before_start" type="checkbox" :disabled="!editable" class="rounded border-gray-300 text-lavoro-blue"
                    @change="changed" />
                vóór de start
            </label>
            <span class="w-28 text-right text-sm tabular-nums text-gray-500">{{ nlCurrency(gross * (Number(term.percent) || 0) / 100) }}</span>
            <button v-if="editable" type="button" class="rounded-md p-1 text-gray-400 hover:text-red-600" aria-label="Termijn weghalen"
                @click="remove(index)">
                <Trash2Icon class="size-4" />
            </button>
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
            <button v-if="editable" type="button" @click="add"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-slate-600 px-3 py-1.5 font-medium hover:bg-gray-50 dark:hover:bg-slate-800">
                <PlusIcon class="size-4" /> Termijn toevoegen
            </button>
            <span v-if="terms.length" :class="total === 100 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                Samen {{ nlQuantity(total) }}%<template v-if="isConsumer">, waarvan {{ nlQuantity(upfront) }}% vóór de start
                    (ten hoogste {{ nlQuantity(maxUpfront) }}%)</template>
            </span>
        </div>
        <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { PlusIcon, Trash2Icon } from '@lucide/vue'
import { BOXED_FIELD, NO_SPIN } from '@/Components/Quotes/quoteColumns'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * When the customer pays what: terms that add up to 100%, each with what it
 * comes to on the total incl. VAT. A term ticked "vóór de start" is paid before
 * the work begins; a private customer may be asked only so much of that, which
 * the settings say. Saved as a whole whenever a term changes; no terms at all
 * means nothing is agreed beyond the payment terms.
 */
defineProps({
    editable: { type: Boolean, default: false },
    gross: { type: Number, required: true },
    isConsumer: { type: Boolean, default: false },
    maxUpfront: { type: Number, required: true },
    error: { type: String, default: null },
})

const schedule = defineModel({ type: Array, default: null })
const emit = defineEmits(['save'])

const terms = ref([])
watch(schedule, (value) => { terms.value = (value ?? []).map(term => ({ ...term })) }, { immediate: true })

const total = computed(() => Math.round(terms.value.reduce((sum, term) => sum + (Number(term.percent) || 0), 0) * 100) / 100)
const upfront = computed(() => terms.value.filter(term => term.before_start).reduce((sum, term) => sum + (Number(term.percent) || 0), 0))

function changed() {
    schedule.value = terms.value.length ? terms.value.map(term => ({ ...term })) : null
    emit('save')
}

function add() {
    terms.value.push({ description: '', percent: Math.max(0, 100 - total.value), before_start: false })
}

function remove(index) {
    terms.value.splice(index, 1)
    changed()
}
</script>
