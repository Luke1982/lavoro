import { computed, nextTick, reactive, toValue, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

/**
 * The state of a new product, shared by every place that makes one: the products
 * page and the machine forms that make a missing product on the spot. Only the
 * way it is sent differs, so that stays with the caller.
 *
 * Attributes belong to a product type, so the ones offered follow the chosen
 * type and what was picked for the previous type is let go.
 *
 * @param {Array|import('vue').Ref<Array>|Function} productAttributes
 */
export function useProductForm(productAttributes) {
    const form = useForm({
        product_type_id: null,
        brand_id: null,
        model: '',
        description: '',
        part_no: '',
        start_sell: '',
        end_sell: '',
        retail_price: null,
        purchase_price: null,
        bundle: false,
        registable: true,
    })

    const attributeValues = reactive({})

    const attributesForType = computed(() =>
        form.product_type_id
            ? (toValue(productAttributes) ?? []).filter(attr => attr.product_type_ids.includes(form.product_type_id))
            : []
    )

    function clearAttributeValues() {
        Object.keys(attributeValues).forEach(key => delete attributeValues[key])
    }

    watch(() => form.product_type_id, clearAttributeValues)

    /** What the server receives: the fields, plus the attributes this type has and that were filled in. */
    function payload(data = form.data()) {
        return {
            ...data,
            attributes: attributesForType.value
                .filter(attr => attributeValues[attr.id])
                .map(attr => ({
                    product_attribute_id: attr.id,
                    product_attribute_value_id: attributeValues[attr.id]?.id ?? attributeValues[attr.id],
                })),
        }
    }

    /** Back to an empty product, without the errors of the last attempt. */
    function reset() {
        form.reset()
        form.clearErrors()
        clearAttributeValues()
    }

    /**
     * Starts from an existing product, for a copy. The attributes are set a tick
     * later, after the type change has cleared what the previous type had.
     */
    async function fill(product) {
        form.product_type_id = product.product_type_id
        form.brand_id = product.brand_id
        form.model = product.model
        form.description = product.description ?? ''
        form.part_no = product.part_no ?? ''
        form.start_sell = product.start_sell ?? ''
        form.end_sell = product.end_sell ?? ''
        form.retail_price = product.retail_price ?? null
        form.purchase_price = product.purchase_price ?? null
        form.bundle = Boolean(product.bundle)
        form.registable = product.registable ?? true

        await nextTick()
        clearAttributeValues()
        ;(product.product_attribute_valueables ?? []).forEach(valueable => {
            attributeValues[valueable.product_attribute_id] = valueable.product_attribute_value_id
        })
    }

    return { form, attributeValues, attributesForType, payload, reset, fill }
}
