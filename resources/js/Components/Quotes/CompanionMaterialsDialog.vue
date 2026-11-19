<template>
    <ModalDialog :open="open" @update:open="(value) => !value && emit('cancel')" max-width-class="sm:max-w-2xl"
        :title="product ? `${nlQuantity(quantity)} × ${product.name}` : ''">
        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
            Welke materialen bied je mee aan? De aantallen zijn voor {{ nlQuantity(quantity) }} stuks.
        </p>

        <div v-if="asked.length" class="mt-4 divide-y divide-gray-200 dark:divide-slate-700">
            <div v-for="row in asked" :key="row.id" class="flex items-center gap-3 py-2">
                <SwitchComponent v-model="row.chosen" />
                <div class="flex-grow min-w-0">
                    <div class="text-sm font-medium truncate" :class="row.chosen ? '' : 'text-gray-400 dark:text-slate-500'">
                        {{ row.name }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">
                        {{ nlCurrency(row.price) }}{{ row.unit ? ' per ' + row.unit : '' }}
                        · {{ row.prompt === 'ask' ? 'standaard aan' : 'voorstel' }}
                    </div>
                </div>
                <div class="w-28">
                    <TextInput v-model="row.quantity" type="number" :disabled="!row.chosen"
                        :has-error="Boolean(errorFor(row))" :error-message="errorFor(row)" />
                </div>
            </div>
        </div>

        <div v-if="always.length" class="mt-4 rounded-lavoro-sm bg-slate-50 dark:bg-slate-800/50 p-3">
            <div class="text-xs font-bold text-slate-500 dark:text-slate-300 mb-1">Komt altijd mee</div>
            <div v-for="row in always" :key="row.id" class="flex justify-between text-sm text-slate-600 dark:text-slate-300">
                <span>{{ row.name }}</span>
                <span class="tabular-nums">{{ nlQuantity(row.quantity * quantity) }}{{ row.unit ? ' ' + row.unit : '' }}</span>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="emit('cancel')"
                class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                Annuleren
            </button>
            <button type="button" @click="confirm" :disabled="processing"
                class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60">
                Toevoegen aan offerte
            </button>
        </div>
    </ModalDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * The question that comes up when a product with standard materials is offered.
 * What is set to be asked starts ticked on, what is only suggested starts off;
 * the quantity is the standard per unit times the number of units. What always
 * comes along is shown, not asked: the server adds it either way.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    product: { type: Object, default: null },
    quantity: { type: Number, default: 1 },
    errors: { type: Object, default: () => ({}) },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'confirm'])

const defaults = computed(() => props.product?.defaults ?? [])
const always = computed(() => defaults.value.filter(row => row.prompt === 'always'))
const asked = ref([])
const chosen = computed(() => asked.value.filter(row => row.chosen))

watch(() => [props.open, props.product], () => {
    if (!props.open) return
    asked.value = defaults.value
        .filter(row => row.prompt !== 'always')
        .map(row => ({ ...row, chosen: row.prompt === 'ask', quantity: Math.round(row.quantity * props.quantity * 100) / 100 }))
}, { immediate: true })

/** The server numbers the chosen materials in the order they were sent, so the error of row n is under its place among them. */
function errorFor(row) {
    const index = chosen.value.indexOf(row)
    return index === -1 ? null : props.errors[`materials.${index}.quantity`] ?? props.errors[`materials.${index}.default_id`] ?? null
}

function confirm() {
    emit('confirm', chosen.value.map(row => ({ default_id: row.id, quantity: row.quantity })))
}
</script>
