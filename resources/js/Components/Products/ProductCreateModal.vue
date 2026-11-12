<template>
    <QuickCreateModal v-model:open="open" title="Nieuw product" submit-label="Product aanmaken" url="/products"
        :form="form" :payload="payload" :loading="loading" :error="loadError" @created="emit('created', $event)">
        <ProductFormFields :form="form" :attribute-values="attributeValues" :attributes="attributesForType"
            :brands="options.brands" :product-types="options.productTypes" />
    </QuickCreateModal>
</template>

<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import QuickCreateModal from '@/Components/UI/QuickCreateModal.vue'
import ProductFormFields from '@/Components/Products/ProductFormFields.vue'
import { useProductForm } from '@/Composables/useProductForm'

/**
 * Makes a product on top of the form that needed it, starting from the model
 * that was typed. The brands, types and attributes are fetched each time it
 * opens: one made in another tab a minute ago belongs in the list.
 */
const open = defineModel('open', { type: Boolean, default: false })

const props = defineProps({
    /** What was typed in the picker, taken as the model. */
    typed: { type: String, default: '' },
})

const emit = defineEmits(['created'])

const options = ref({ brands: [], productTypes: [], productAttributes: [] })
const loading = ref(false)
const loadError = ref('')

const { form, attributeValues, attributesForType, payload, reset } = useProductForm(
    () => options.value.productAttributes
)

watch(open, async (isOpen) => {
    if (!isOpen) return

    reset()
    form.model = props.typed
    loadError.value = ''
    loading.value = true

    try {
        options.value = (await axios.get('/products/create')).data
    } catch {
        loadError.value = 'De keuzelijsten voor een product konden niet worden geladen.'
    } finally {
        loading.value = false
    }
})
</script>
