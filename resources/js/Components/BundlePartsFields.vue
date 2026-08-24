<template>
    <div v-if="parts.length">
        <p class="text-xs font-medium text-gray-500 mb-2">Onderdelen</p>
        <div v-for="part in parts" :key="part.productable_id" class="mb-4">
            <div class="flex items-center justify-between gap-2 mb-1">
                <label class="block text-xs text-gray-500">{{ part.relation_name }}: {{ part.name }}</label>
                <input v-if="part.flex_quantity" type="number" :min="part.is_required ? 1 : 0" max="999"
                    :value="quantities[part.productable_id] ?? 0"
                    @input="quantities[part.productable_id] = Number($event.target.value)"
                    class="w-20 rounded-md border-0 py-1 px-2 text-sm text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900" />
                <span v-else class="text-xs text-gray-400">{{ part.quantity }}×</span>
            </div>
            <p v-if="!part.requires_serial" class="text-xs text-gray-400 italic">
                Geen serienummer nodig — {{ quantityFor(part) }} stuks worden geregistreerd.
            </p>
            <div v-else v-for="row in rowsFor(part)" :key="row.index" class="mb-2 flex items-start gap-2">
                <TextInput :model-value="serialOf(row)" @update:modelValue="setSerial(row, $event)" class="flex-1"
                    :placeholder="`Serienummer ${part.name} (${row.position + 1}/${quantityFor(part)})`"
                    :has-error="!!errors[`child_assets.${row.index}.serial_number`]"
                    :error-message="errors[`child_assets.${row.index}.serial_number`] ?? ''" />
                <ScanSerialButton class="mt-0.5" @picked="setSerial(row, $event)" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ScanSerialButton from '@/Components/UI/ScanSerialButton.vue'

/**
 * The parts that come along when a machine is built, and the rows they turn into.
 *
 * A part with a fixed aantal contributes that many child machines, as it always has. A part
 * whose aantal is vrij contributes whatever is filled in here, since one omvormer takes as
 * many panelen as this roof happens to fit. Only parts whose own product carries a
 * serienummer get a field to type in; the rest are counted.
 *
 * The v-model is the `child_assets` payload the machine form posts, so the aantallen, the
 * row indexes the server files its errors under, and what gets sent stay one description.
 */
const props = defineProps({
    productId: { type: [Number, String], default: null },
    /** Parts keyed by the product they hang under, as the server ships them. */
    bundleParts: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const model = defineModel({ type: Array, default: () => [] })

const quantities = reactive({})
const serials = ref({})

const parts = computed(() =>
    (props.bundleParts[props.productId] ?? []).filter(part => part.is_required || part.flex_quantity)
)

function quantityFor(part) {
    if (!part.flex_quantity) return part.quantity

    return Math.max(part.is_required ? 1 : 0, quantities[part.productable_id] ?? 0)
}

/** One row per child machine that will be created, in payload order. */
const rows = computed(() =>
    parts.value
        .flatMap(part => Array.from({ length: quantityFor(part) }, (_, position) => ({ part, position })))
        .map((row, index) => ({ ...row, index }))
)

function rowsFor(part) {
    return rows.value.filter(row => row.part.productable_id === part.productable_id)
}

/** Kept per part and position rather than per row index, so a changed aantal keeps what was typed. */
function serialKey(row) {
    return `${row.part.productable_id}:${row.position}`
}

function serialOf(row) {
    return serials.value[serialKey(row)] ?? ''
}

function setSerial(row, value) {
    serials.value = { ...serials.value, [serialKey(row)]: value }
}

watch(() => props.productId, () => {
    Object.keys(quantities).forEach(key => delete quantities[key])
    parts.value.forEach(part => {
        if (part.flex_quantity) quantities[part.productable_id] = part.is_required ? 1 : 0
    })
    serials.value = {}
}, { immediate: true })

const payload = computed(() =>
    rows.value.map(row => ({
        productable_id: row.part.productable_id,
        serial_number: row.part.requires_serial ? serialOf(row) : '',
    }))
)

watch(payload, (value) => { model.value = value }, { immediate: true })
</script>
