<template>
    <div class="divide-y divide-gray-200 dark:divide-slate-700">
        <Field>
            <template #label>Naam <span class="text-red-500">*</span></template>
            <TextInput v-model="form.name" placeholder="Naam klant" :has-error="Boolean(form.errors.name)"
                :error-message="form.errors.name" />
        </Field>
        <Field label="E-mail">
            <TextInput v-model="form.email" type="email" placeholder="info@bedrijf.nl"
                :has-error="Boolean(form.errors.email)" :error-message="form.errors.email" />
        </Field>
        <Field label="Telefoon">
            <TextInput v-model="form.phone" placeholder="0612345678" :has-error="Boolean(form.errors.phone)"
                :error-message="form.errors.phone" />
        </Field>
        <Field label="Adres">
            <TextInput v-model="form.address" placeholder="Straatnaam 1" :has-error="Boolean(form.errors.address)"
                :error-message="form.errors.address" />
        </Field>
        <Field label="Postcode / Plaats">
            <div class="grid grid-cols-2 gap-2">
                <TextInput v-model="form.postal_code" placeholder="1234AB"
                    :has-error="Boolean(form.errors.postal_code)" :error-message="form.errors.postal_code" />
                <TextInput v-model="form.city" placeholder="Plaats" :has-error="Boolean(form.errors.city)"
                    :error-message="form.errors.city" />
            </div>
        </Field>
        <Field label="Land">
            <TextInput v-model="form.country" placeholder="Nederland" :has-error="Boolean(form.errors.country)"
                :error-message="form.errors.country" />
        </Field>
        <Field label="Locatiecode">
            <TextInput v-model="form.location_code" placeholder="Locatiecode"
                :has-error="Boolean(form.errors.location_code)" :error-message="form.errors.location_code" />
        </Field>
    </div>
</template>

<script setup>
import TextInput from '@/Components/UI/TextInput.vue'
import Field from '@/Components/UI/DrawerField.vue'

/** The fields of a new customer, wherever one is made. */
const form = defineModel('form', { type: Object, required: true })
</script>
