<template>
    <div :class="['grid grid-cols-1 gap-2', !stacked && 'sm:grid-cols-2']">
        <ComboBox :options="typeOptions" :model-value="productTypeId" :placeholder="allLabel" :disabled="disabled"
            :has-error="Boolean(typeError)" :error-message="typeError ?? ''"
            @update:model-value="pickType" />
        <ComboBox :options="modelOptions" :model-value="productId" placeholder="Of een bepaald model" :disabled="disabled"
            :has-external-searching="productsUseAjax" :searching="searching"
            :has-error="Boolean(modelError)" :error-message="modelError ?? ''"
            @change="search" @update:model-value="pickModel" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { useModelOptions } from './useModelOptions'

/**
 * Which machines a formula line is about: a machine type, which takes its
 * subtypes along, or one model. Picking the one empties the other, because a
 * line about a model is about that model whatever its type.
 */
const props = defineProps({
    productTypeId: { type: Number, default: null },
    productId: { type: Number, default: null },
    productTypes: { type: Array, default: () => [] },
    productOptions: { type: Array, default: () => [] },
    productsUseAjax: { type: Boolean, default: false },
    allLabel: { type: String, default: 'Alle machines' },
    /** One picker under the other, for a narrow column. */
    stacked: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    typeError: { type: String, default: null },
    modelError: { type: String, default: null },
})

const emit = defineEmits(['update:productTypeId', 'update:productId'])

const typeOptions = computed(() => props.productTypes.map(type => ({ id: type.id, name: type.name })))

const { options: modelOptions, searching, search } =
    useModelOptions(props.productOptions, props.productsUseAjax, () => props.productId)

function pickType(id) {
    emit('update:productTypeId', id ?? null)
    if (id) emit('update:productId', null)
}

function pickModel(id) {
    emit('update:productId', id ?? null)
    if (id) emit('update:productTypeId', null)
}
</script>
