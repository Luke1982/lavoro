import { describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'
import { useProductForm } from '../useProductForm'

/**
 * The products page and the machine forms make a product from the same state.
 * What goes to the server has to be what it always was: the fields, plus only
 * the attributes the chosen type has.
 */
const spanning = { id: 1, name: 'Spanning', values: [{ id: 11, value: '18V' }], product_type_ids: [5] }
const kleur = { id: 2, name: 'Kleur', values: [{ id: 21, value: 'Blauw' }], product_type_ids: [6] }

describe('useProductForm', () => {
    it('offers the attributes of the chosen type only', async () => {
        const { form, attributesForType } = useProductForm([spanning, kleur])

        form.product_type_id = 5
        await nextTick()

        expect(attributesForType.value).toEqual([spanning])
    })

    it('sends the attributes that were filled in for this type', async () => {
        const { form, attributeValues, payload } = useProductForm([spanning, kleur])

        form.product_type_id = 5
        await nextTick()
        attributeValues[1] = 11
        attributeValues[2] = 21

        expect(payload().attributes).toEqual([{ product_attribute_id: 1, product_attribute_value_id: 11 }])
    })

    it('lets go of the attributes of the previous type when the type changes', async () => {
        const { form, attributeValues } = useProductForm([spanning, kleur])

        form.product_type_id = 5
        await nextTick()
        attributeValues[1] = 11
        form.product_type_id = 6
        await nextTick()

        expect(attributeValues).toEqual({})
    })

    it('copies a product including its attributes', async () => {
        const { form, attributeValues, fill } = useProductForm([spanning, kleur])

        await fill({
            product_type_id: 5,
            brand_id: 3,
            model: 'GSR 18V-55',
            bundle: 0,
            registable: false,
            product_attribute_valueables: [{ product_attribute_id: 1, product_attribute_value_id: 11 }],
        })

        expect(form.model).toBe('GSR 18V-55')
        expect(form.bundle).toBe(false)
        expect(form.registable).toBe(false)
        expect({ ...attributeValues }).toEqual({ 1: 11 })
    })

    it('follows a list of attributes that arrives after the form was made', () => {
        const attributes = ref([])
        const { form, attributesForType } = useProductForm(attributes)

        form.product_type_id = 5
        attributes.value = [spanning]

        expect(attributesForType.value).toEqual([spanning])
    })
})
