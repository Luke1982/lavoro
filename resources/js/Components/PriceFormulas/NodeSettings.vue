<template>
    <div class="space-y-4 text-sm">
        <div>
            <p class="font-semibold text-gray-900 dark:text-white">{{ definition.label }}</p>
            <p class="text-xs text-gray-500 dark:text-slate-400">{{ definition.description }}</p>
        </div>

        <TextInput id="setting-title" data-formula-field="title" :model-value="node.data?.title ?? ''" type="text" label="Naam op het blok"
            :placeholder="definition.label" :disabled="readonly" @update:model-value="value => set('title', value || null)" />

        <div v-for="field in shownFields" :key="field.key" :data-formula-field="field.type" :data-formula-field-key="field.key">
            <template v-if="field.type === 'number' || field.type === 'text'">
                <TextInput :id="'setting-' + field.key" :model-value="node.data?.[field.key] ?? ''"
                    :type="field.type === 'number' ? 'number' : 'text'" step="any" :label="labelOf(field)"
                    :placeholder="field.default == null ? '' : String(field.default)" :disabled="readonly"
                    @update:model-value="v => set(field.key, field.type === 'number' ? toNumber(v) : v)" />
            </template>

            <template v-else-if="field.type === 'select'">
                <label :for="'setting-' + field.key" class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">
                    {{ field.label }}
                </label>
                <select :id="'setting-' + field.key" :value="value(field)" :disabled="readonly"
                    :class="'mt-2 ' + SELECT_CLASS"
                    @change="event => set(field.key, event.target.value)">
                    <option v-for="(label, key) in field.options" :key="key" :value="key">{{ label }}</option>
                </select>
            </template>

            <ComboBox v-else-if="pickers[field.type]" :label="field.label" :options="pickers[field.type].options"
                :model-value="field.list ? value(field) ?? [] : value(field)" :multiple="Boolean(field.list)"
                :placeholder="pickers[field.type].placeholder" :disabled="readonly"
                :has-external-searching="pickers[field.type].searchesServer" :searching="pickers[field.type].searching"
                @change="query => pickers[field.type].search?.(query)" @update:model-value="v => set(field.key, v)" />

            <div v-else-if="field.type === 'tiers'">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-300">{{ field.label }}</p>
                <div v-for="(step, index) in list(field)" :key="index" class="mt-2 flex items-end gap-2">
                    <div class="w-24">
                        <TextInput :id="'setting-step-from-' + index" :model-value="step.from" type="number" min="2"
                            label="Vanaf stuk" :disabled="readonly"
                            @update:model-value="v => setItem(field, index, 'from', toNumber(v))" />
                    </div>
                    <div class="flex-1">
                        <TextInput :id="'setting-step-amount-' + index" :model-value="step.amount" type="number" step="any"
                            label="Per stuk" :disabled="readonly"
                            @update:model-value="v => setItem(field, index, 'amount', toNumber(v))" />
                    </div>
                    <button v-if="!readonly" type="button" class="pb-2 text-gray-400 hover:text-red-600" title="Stap verwijderen"
                        @click="removeItem(field, index)">
                        <XMarkIcon class="size-4" />
                    </button>
                </div>
                <button v-if="!readonly" type="button" class="mt-2 text-sm font-medium text-lavoro-blue hover:underline dark:text-blue-400"
                    @click="addStep(field)">
                    + Stap
                </button>
            </div>

            <div v-else-if="field.type === 'components'">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-300">{{ field.label }}</p>
                <div v-for="(component, index) in list(field)" :key="index"
                    class="mt-2 space-y-2 rounded-md border border-gray-200 p-2 dark:border-slate-600">
                    <div class="flex items-center gap-2">
                        <div class="w-20">
                            <TextInput :model-value="component.quantity" type="number" min="1" :disabled="readonly"
                                @update:model-value="v => setItem(field, index, 'quantity', toNumber(v))" />
                        </div>
                        <span class="text-gray-500">×</span>
                        <span class="flex-1" />
                        <button v-if="!readonly" type="button" class="text-gray-400 hover:text-red-600" title="Onderdeel verwijderen"
                            @click="removeItem(field, index)">
                            <XMarkIcon class="size-4" />
                        </button>
                    </div>
                    <MachinePicker :product-type-id="component.product_type_id" :product-id="component.product_id"
                        :product-types="productTypes" :product-options="productOptions"
                        :products-use-ajax="productsUseAjax" :disabled="readonly"
                        @update:product-type-id="v => setItem(field, index, 'product_type_id', v)"
                        @update:product-id="v => setItem(field, index, 'product_id', v)" />
                </div>
                <button v-if="!readonly" type="button" class="mt-2 text-sm font-medium text-lavoro-blue hover:underline dark:text-blue-400"
                    @click="addComponent(field)">
                    + Onderdeel
                </button>
            </div>
        </div>

        <button v-if="!readonly" type="button" class="inline-flex items-center gap-1 text-sm font-medium text-red-600 hover:underline"
            data-formula-remove @click="$emit('remove')">
            <TrashIcon class="size-4" /> Blok verwijderen
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import MachinePicker from '@/Components/PriceFormulas/MachinePicker.vue'
import { useModelOptions } from './useModelOptions'
import { SELECT_CLASS, settingOf } from './formulaEditor'

/** The settings of the block selected on the canvas; every change goes back to the canvas at once. */
const props = defineProps({
    node: { type: Object, required: true },
    definition: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    attributes: { type: Array, default: () => [] },
    productOptions: { type: Array, default: () => [] },
    productsUseAjax: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
})

const emit = defineEmits(['remove', 'change'])

const { options: modelOptions, searching, search } =
    useModelOptions(props.productOptions, props.productsUseAjax, () => props.node.data?.product_id)

const value = field => settingOf(props.node, props.definition, field.key)
const list = field => value(field) ?? []

/** The settings that point at a record: what each one picks from, and what it says while nothing is picked. */
const pickers = computed(() => ({
    product_type: { options: props.productTypes, placeholder: 'Kies een producttype' },
    product: {
        options: modelOptions.value,
        placeholder: 'Kies een model',
        searchesServer: props.productsUseAjax,
        searching: searching.value,
        search,
    },
    brand: { options: props.brands, placeholder: 'Kies een merk' },
    attribute: { options: props.attributes, placeholder: 'Kies een kenmerk' },
}))

/** A setting that only matters for one choice, such as "tot en met" for "tussen", shows only then. */
const shownFields = computed(() => props.definition.fields.filter(field => !field.shown_when
    || Object.entries(field.shown_when).every(([key, wanted]) => settingOf(props.node, props.definition, key) === wanted)))

/** The value of a kenmerk filter is compared in the unit of that kenmerk, so the label says which. */
function labelOf(field) {
    if (!['value', 'value_to'].includes(field.key) || props.node.type !== 'filter_attribute') return field.label
    const unit = props.attributes.find(attribute => attribute.id === props.node.data?.product_attribute_id)?.unit
    return unit ? `${field.label} (in ${unit})` : field.label
}

const toNumber = v => (v === '' || v === null || v === undefined ? null : Number(v))

function set(key, v) {
    emit('change', { ...(props.node.data ?? {}), [key]: v })
}

function setItem(field, index, key, v) {
    const items = list(field).map((item, i) => (i === index ? { ...item, [key]: v } : item))
    set(field.key, items)
}

function removeItem(field, index) {
    set(field.key, list(field).filter((_, i) => i !== index))
}

/** A new staffel starts its first step at this piece, and every next step this many pieces further on. */
const FIRST_STEP_FROM = 6
const STEP_SIZE = 5

function addStep(field) {
    const last = list(field).at(-1)
    set(field.key, [...list(field), {
        from: last ? Number(last.from) + STEP_SIZE : FIRST_STEP_FROM,
        amount: last?.amount ?? settingOf(props.node, props.definition, 'amount') ?? 0,
    }])
}

function addComponent(field) {
    set(field.key, [...list(field), { product_type_id: null, product_id: null, quantity: 1 }])
}
</script>
