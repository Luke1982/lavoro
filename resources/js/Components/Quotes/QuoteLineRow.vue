<template>
    <div class="grid grid-cols-12 gap-2 px-4 py-2 items-start text-sm"
        :class="[child ? 'bg-slate-50/70 dark:bg-slate-800/30' : 'border-t border-gray-200 dark:border-slate-700',
            muted ? 'opacity-60' : '']">
        <div :class="isText ? 'col-span-11' : 'col-span-12 md:col-span-4'" class="flex items-start gap-2 min-w-0">
            <CornerDownRightIcon v-if="child" class="size-4 mt-2 shrink-0 text-gray-400" />
            <div class="flex-grow min-w-0">
                <input v-if="editable" v-model="form.description" @change="save('description')"
                    :class="[field, isText ? 'font-semibold' : '']" aria-label="Omschrijving" />
                <span v-else :class="isText ? 'font-semibold' : ''">{{ line.description }}</span>
                <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 flex gap-2">
                    <span>{{ KIND_LABELS[line.kind] }}</span>
                    <span v-if="line.is_optional" class="text-amber-600 dark:text-amber-400">optioneel, niet in het totaal</span>
                </div>
                <p v-for="error in form.errors" :key="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
            </div>
        </div>

        <template v-if="!isText">
            <div class="col-span-3 md:col-span-1">
                <input v-if="editable" v-model="form.quantity" type="number" step="any" min="0" @change="save('quantity')"
                    :class="[field, 'text-right']" aria-label="Aantal" />
                <span v-else class="block text-right tabular-nums">{{ nlQuantity(line.quantity) }}</span>
            </div>
            <div class="col-span-3 md:col-span-1">
                <input v-if="editable" v-model="form.unit" @change="save('unit')" :class="field" placeholder="eenheid"
                    aria-label="Eenheid" />
                <span v-else class="text-gray-500">{{ line.unit }}</span>
            </div>
            <div class="col-span-6 md:col-span-2">
                <input v-if="editable" v-model="form.unit_price" type="number" step="0.01" @change="save('unit_price')"
                    :class="[field, 'text-right']" aria-label="Prijs per stuk" />
                <span v-else class="block text-right tabular-nums">{{ nlCurrency(line.unit_price) }}</span>
                <div v-if="seeMargins" class="mt-1 flex items-center gap-1 text-xs text-gray-500">
                    <span>inkoop</span>
                    <input v-if="editable" v-model="form.cost_price" type="number" step="0.01" @change="save('cost_price')"
                        :class="[field, 'text-right py-0.5']" aria-label="Inkoopprijs" />
                    <span v-else class="tabular-nums">{{ line.cost_price === null ? '–' : nlCurrency(line.cost_price) }}</span>
                </div>
            </div>
            <div class="col-span-4 md:col-span-1">
                <input v-if="editable" v-model="form.discount_percent" type="number" step="any" min="0" max="100"
                    @change="save('discount_percent')" :class="[field, 'text-right']" aria-label="Korting %" />
                <span v-else-if="line.discount_percent" class="block text-right">{{ nlQuantity(line.discount_percent) }}%</span>
            </div>
            <div class="col-span-4 md:col-span-1">
                <select v-if="editable" v-model.number="form.vat_percent" @change="save('vat_percent')" :class="field"
                    aria-label="Btw">
                    <option v-for="rate in vatRates" :key="rate" :value="rate">{{ rate }}%</option>
                </select>
                <span v-else class="text-gray-500">{{ nlQuantity(line.vat_percent) }}%</span>
            </div>
            <div class="col-span-3 md:col-span-1 text-right font-medium tabular-nums pt-1.5">{{ nlCurrency(line.net) }}</div>
        </template>

        <div class="col-span-1 flex justify-end gap-2 pt-1.5">
            <button v-if="editable && !child && !isText" type="button" @click="toggleOptional"
                v-tooltip="line.is_optional ? 'Weer meetellen in het totaal' : 'Optioneel maken: de klant kiest'">
                <ListChecksIcon class="size-4" :class="line.is_optional ? 'text-amber-500' : 'text-gray-400'" />
            </button>
            <button v-if="editable" type="button" @click="remove"
                v-tooltip="line.children?.length ? 'Verwijderen, met de materialen eronder' : 'Verwijderen'">
                <TrashIcon class="size-4 text-red-500" />
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { CornerDownRightIcon, ListChecksIcon, TrashIcon } from '@lucide/vue'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * One line of a quote, edited where it stands: a field is saved the moment it is
 * left. A material that came along with a product is drawn under it, indented,
 * and dimmed when its product is optional, because then it is optional too.
 *
 * The page keys a row on its id and its updated_at, so a row whose line changed
 * on the server, also by a listener, starts again from what the server holds.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
    line: { type: Object, required: true },
    vatRates: { type: Array, required: true },
    child: { type: Boolean, default: false },
    editable: { type: Boolean, default: false },
    seeMargins: { type: Boolean, default: false },
    muted: { type: Boolean, default: false },
})

const KIND_LABELS = { product: 'Product', material: 'Materiaal', free: 'Eigen regel', text: 'Tekst' }

const field = 'w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm px-2 py-1.5 focus:ring-2 focus:ring-indigo-600'

const isText = computed(() => props.line.kind === 'text')
const url = computed(() => `/quotes/${props.quoteId}/lines/${props.line.id}`)

const form = useForm({
    description: props.line.description,
    quantity: props.line.quantity,
    unit: props.line.unit,
    unit_price: props.line.unit_price,
    cost_price: props.line.cost_price ?? null,
    discount_percent: props.line.discount_percent,
    vat_percent: props.line.vat_percent,
})

function save(name) {
    if (form[name] === props.line[name]) return
    form.transform(data => ({ [name]: data[name] === '' ? null : data[name] }))
        .patch(url.value, { preserveScroll: true, preserveState: true })
}

function toggleOptional() {
    router.patch(url.value, { is_optional: !props.line.is_optional }, { preserveScroll: true })
}

function remove() {
    const along = props.line.children?.length ? ` en de ${props.line.children.length} materialen eronder` : ''
    if (!confirm(`${props.line.description}${along} van de offerte halen?`)) return
    router.delete(url.value, { preserveScroll: true })
}
</script>
