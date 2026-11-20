<template>
    <button v-if="editable" type="button" class="draghandle cursor-grab text-gray-300 hover:text-gray-500 dark:text-slate-600" aria-label="Verslepen">
        <GripVerticalIcon class="size-4" />
    </button>
    <div class="min-w-0 flex-1 basis-40">
        <input v-if="editable" v-model="form.description" @change="save('description')" :class="GHOST_FIELD" aria-label="Omschrijving" />
        <span v-else>{{ line.description }}</span>
        <p v-for="error in form.errors" :key="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
    <div :class="COLUMNS.quantity">
        <input v-if="editable" v-model="form.quantity" type="number" step="any" min="0" @change="save('quantity')"
            :class="[GHOST_FIELD, NO_SPIN, 'text-right tabular-nums']" aria-label="Aantal" />
        <div v-else class="text-right tabular-nums">{{ nlQuantity(line.quantity) }}</div>
    </div>
    <div :class="COLUMNS.unit">
        <select v-if="editable" v-model="form.unit" @change="save('unit')" :class="[GHOST_FIELD, 'pr-6']" aria-label="Eenheid">
            <option :value="null">–</option>
            <option v-for="unit in unitOptions" :key="unit" :value="unit">{{ unit }}</option>
        </select>
        <span v-else class="text-gray-500">{{ line.unit }}</span>
    </div>
    <div :class="COLUMNS.price">
        <CurrencyInput v-if="editable" v-model="form.unit_price" @change="save('unit_price')" :ring="false"
            class="rounded-md hover:ring-1 hover:ring-gray-200 dark:hover:ring-slate-600 text-right" aria-label="Prijs per stuk" />
        <div v-else class="text-right tabular-nums">{{ nlCurrency(line.unit_price) }}</div>
    </div>
    <div :class="COLUMNS.vat" class="hidden md:block" />
    <div :class="COLUMNS.total" class="text-right tabular-nums">{{ nlCurrency(line.net) }}</div>
    <div :class="COLUMNS.actions" class="flex justify-end pr-3">
        <button v-if="editable" type="button" @click="remove" v-tooltip="'Verwijderen'"
            class="opacity-0 group-hover:opacity-100 focus:opacity-100 text-gray-400 hover:text-red-600">
            <Trash2Icon class="size-4" />
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { GripVerticalIcon, Trash2Icon } from '@lucide/vue'
import { COLUMNS, GHOST_FIELD, NO_SPIN, unitChoices } from '@/Components/Quotes/quoteColumns'
import { useSaveField } from '@/Components/Quotes/useSaveField'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/** A material under a product: quiet until it is pointed at, saved the moment a field is left. */
const props = defineProps({
    quoteId: { type: Number, required: true },
    line: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
})

const unitOptions = computed(() => unitChoices(props.units, props.line.unit))
const url = computed(() => `/quotes/${props.quoteId}/lines/${props.line.id}`)

const form = useForm({
    description: props.line.description,
    quantity: props.line.quantity,
    unit: props.line.unit,
    unit_price: props.line.unit_price,
})

const save = useSaveField(form, () => url.value, () => props.line)

function remove() {
    if (!confirm(`${props.line.description} van de offerte halen?`)) return
    router.delete(url.value, { preserveScroll: true })
}
</script>
