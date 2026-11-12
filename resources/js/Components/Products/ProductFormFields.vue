<template>
    <div class="divide-y divide-gray-200 dark:divide-slate-700">
        <Field label="Producttype">
            <ComboBox v-model="form.product_type_id" :options="productTypeOptions" placeholder="Selecteer producttype"
                :create-label="canCreateProductType ? 'Nieuw producttype' : ''" @create="createProductType"
                :has-error="Boolean(form.errors.product_type_id)" :error-message="form.errors.product_type_id" />
        </Field>
        <Field label="Merk">
            <ComboBox v-model="form.brand_id" :options="brandOptions" placeholder="Selecteer merk"
                :create-label="canCreateBrand ? 'Nieuw merk' : ''" @create="createBrand"
                :has-error="Boolean(form.errors.brand_id)" :error-message="form.errors.brand_id" />
        </Field>
        <Field label="Model">
            <TextInput v-model="form.model" :has-error="Boolean(form.errors.model)"
                :error-message="form.errors.model" />
        </Field>
        <Field label="Beschrijving" tall>
            <textarea v-model="form.description" rows="3" placeholder="Optioneel"
                class="block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm p-2"></textarea>
            <FieldError :message="form.errors.description" />
        </Field>
        <Field label="Artikelnummer">
            <TextInput v-model="form.part_no" placeholder="Optioneel" :has-error="Boolean(form.errors.part_no)"
                :error-message="form.errors.part_no" />
        </Field>
        <Field label="Start verkoop">
            <TextInput v-model="form.start_sell" type="date" :has-error="Boolean(form.errors.start_sell)"
                :error-message="form.errors.start_sell" />
        </Field>
        <Field label="Einde verkoop">
            <TextInput v-model="form.end_sell" type="date" :has-error="Boolean(form.errors.end_sell)"
                :error-message="form.errors.end_sell" />
        </Field>
        <Field label="Verkoopprijs">
            <CurrencyInput v-model="form.retail_price" placeholder="Optioneel"
                :has-error="Boolean(form.errors.retail_price)" :error-message="form.errors.retail_price" />
        </Field>
        <Field label="Inkoopprijs">
            <CurrencyInput v-model="form.purchase_price" placeholder="Optioneel"
                :has-error="Boolean(form.errors.purchase_price)" :error-message="form.errors.purchase_price" />
        </Field>
        <Field>
            <template #label>
                <span class="flex">
                    Bundel
                    <InfoIcon class="h-4 w-4 text-gray-500 mt-0.5 ml-1"
                        v-tooltip="'Een gebundeld product wordt gebruikt om andere producten te groeperen. Machines die van bundels gemaakt worden krijgen geen serienummer.'" />
                </span>
            </template>
            <SwitchComponent v-model="form.bundle" />
        </Field>
        <Field v-if="!form.bundle">
            <template #label>
                <span class="flex">
                    Registreerbaar
                    <InfoIcon class="h-4 w-4 text-gray-500 mt-0.5 ml-1"
                        v-tooltip="'Machines van een registreerbaar product worden stuk voor stuk op serienummer vastgelegd. Zet dit uit voor producten die per stuk geteld worden, zoals zonnepanelen.'" />
                </span>
            </template>
            <SwitchComponent v-model="form.registable" />
        </Field>
        <Field v-for="attribute in attributes" :key="attribute.id" :label="attribute.name">
            <ComboBox v-model="attributeValues[attribute.id]"
                :options="attribute.values.map(value => ({ id: value.id, name: value.value }))"
                :placeholder="`Selecteer ${attribute.name.toLowerCase()}`" />
        </Field>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { InfoIcon } from '@lucide/vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import Field from '@/Components/UI/DrawerField.vue'
import FieldError from '@/Components/UI/DrawerFieldError.vue'
import { hasPermission } from '@/Utilities/Utilities'
import { createRecord } from '@/Utilities/createRecord'

/**
 * The fields of a new product, wherever one is made. A brand or type that is
 * not in the list yet is made from its own picker by typing it, for whoever may
 * make one, so nobody has to leave a half-filled form to go and add it.
 */
const props = defineProps({
    /** The attributes of the chosen type. */
    attributes: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] },
})

/** The form and attribute values from useProductForm, filled in here. */
const form = defineModel('form', { type: Object, required: true })
const attributeValues = defineModel('attributeValues', { type: Object, required: true })

const brandOptions = ref([...props.brands])
const productTypeOptions = ref([...props.productTypes])

watch(() => props.brands, brands => { brandOptions.value = [...brands] })
watch(() => props.productTypes, types => { productTypeOptions.value = [...types] })

const canCreateBrand = hasPermission('brand.create')
const canCreateProductType = hasPermission('producttype.create')

/**
 * Makes the brand or type that was typed, adds it to its picker and selects
 * it. A refusal is said under that picker, whatever field the server named.
 */
async function createAndSelect(url, options, field, name) {
    const { record, errors, message } = await createRecord(url, { name })

    if (!record) {
        form.value.setError(field, Object.values(errors)[0] ?? message)
        return
    }

    options.value = [...options.value, { id: record.id, name: record.name }]
    form.value[field] = record.id
    form.value.clearErrors(field)
}

const createProductType = name => createAndSelect('/producttypes', productTypeOptions, 'product_type_id', name)
const createBrand = name => createAndSelect('/brands', brandOptions, 'brand_id', name)
</script>
