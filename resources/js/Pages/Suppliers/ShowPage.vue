<template>
    <div class="flex items-center mb-6">
        <Link href="/suppliers" class="text-slate-400 text-sm font-medium">Leveranciers</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-200 font-bold text-sm">{{ supplier.name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row mt-2 mb-4">
        <div class="flex flex-col justify-around flex-grow items-start py-2 sm:py-6 gap-3">
            <h1 class="text-2xl font-bold">{{ supplier.name }}</h1>
            <p v-if="supplier.contact_person" class="text-sm text-gray-500 dark:text-slate-400">
                {{ supplier.contact_person }}
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: editable fields -->
        <div class="lg:col-span-2 space-y-6">
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <BuildingOfficeIcon class="size-5 text-gray-500 mr-2" />
                    <span class="text-md font-bold">Leveranciersgegevens</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="flex flex-col gap-6">
                        <EditableTextField v-model="form.name" type="input" label="Naam"
                            :error="form.errors.name" @revert="form.clearErrors('name')" />
                        <EditableTextField v-model="form.email" type="input" inputType="email" label="E-mail"
                            :error="form.errors.email" @revert="form.clearErrors('email')" />
                        <EditableTextField v-model="form.phone" type="input" label="Telefoon"
                            :error="form.errors.phone" @revert="form.clearErrors('phone')" />
                        <EditableTextField v-model="form.mobile" type="input" label="Mobiel"
                            :error="form.errors.mobile" @revert="form.clearErrors('mobile')" />
                        <EditableTextField v-model="form.website" type="input" label="Website"
                            :error="form.errors.website" @revert="form.clearErrors('website')" />
                        <EditableTextField v-model="form.contact_person" type="input" label="Contactpersoon"
                            :error="form.errors.contact_person" @revert="form.clearErrors('contact_person')" />
                    </div>
                    <div class="flex flex-col gap-6">
                        <EditableTextField v-model="form.address" type="input" label="Adres"
                            :error="form.errors.address" @revert="form.clearErrors('address')" />
                        <EditableTextField v-model="form.postal_code" type="input" label="Postcode"
                            :error="form.errors.postal_code" @revert="form.clearErrors('postal_code')" />
                        <EditableTextField v-model="form.city" type="input" label="Plaats"
                            :error="form.errors.city" @revert="form.clearErrors('city')" />
                        <EditableTextField v-model="form.country" type="input" label="Land"
                            :error="form.errors.country" @revert="form.clearErrors('country')" />
                        <EditableTextField v-model="form.iban" type="input" label="IBAN"
                            :error="form.errors.iban" @revert="form.clearErrors('iban')" />
                        <EditableTextField v-model="form.vat_number" type="input" label="BTW-nummer"
                            :error="form.errors.vat_number" @revert="form.clearErrors('vat_number')" />
                        <EditableTextField v-model="form.kvk_number" type="input" label="KVK-nummer"
                            :error="form.errors.kvk_number" @revert="form.clearErrors('kvk_number')" />
                    </div>
                </div>
            </BoxComponent>
        </div>

        <!-- Right: linked records -->
        <div class="space-y-6">
            <!-- Linked products -->
            <BoxComponent>
                <div class="flex items-center mb-3 pb-3 border-b border-gray-200 dark:border-slate-700">
                    <CubeIcon class="size-5 text-gray-500 mr-2" />
                    <span class="text-sm font-medium">Gekoppelde producten</span>
                </div>
                <p v-if="!supplier.products?.length" class="text-sm text-gray-400 italic">Geen producten gekoppeld.</p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-400 border-b dark:border-slate-700">
                            <th class="text-left py-1 font-medium">Product</th>
                            <th class="text-left py-1 font-medium">Artikelnr.</th>
                            <th class="text-center py-1 font-medium">Voorkeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in supplier.products" :key="product.id"
                            class="border-b border-gray-100 dark:border-slate-800">
                            <td class="py-1.5">
                                <Link :href="`/products/${product.id}`" class="text-blue-500 hover:underline">
                                    {{ product.brand.name }} {{ product.model }}
                                </Link>
                            </td>
                            <td class="py-1.5 text-gray-500">{{ product.pivot.article_number || '—' }}</td>
                            <td class="py-1.5 text-center">
                                <span v-if="product.pivot.is_preferred" class="text-green-600 text-xs">✓</span>
                                <span v-else class="text-gray-300 text-xs">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </BoxComponent>

            <!-- Linked materials -->
            <BoxComponent>
                <div class="flex items-center mb-3 pb-3 border-b border-gray-200 dark:border-slate-700">
                    <SwatchIcon class="size-5 text-gray-500 mr-2" />
                    <span class="text-sm font-medium">Gekoppeld materiaal</span>
                </div>
                <p v-if="!supplier.materials?.length" class="text-sm text-gray-400 italic">Geen materiaal gekoppeld.</p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-400 border-b dark:border-slate-700">
                            <th class="text-left py-1 font-medium">Materiaal</th>
                            <th class="text-left py-1 font-medium">Artikelnr.</th>
                            <th class="text-center py-1 font-medium">Voorkeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="material in supplier.materials" :key="material.id"
                            class="border-b border-gray-100 dark:border-slate-800">
                            <td class="py-1.5">{{ material.name }}</td>
                            <td class="py-1.5 text-gray-500">{{ material.pivot.article_number || '—' }}</td>
                            <td class="py-1.5 text-center">
                                <span v-if="material.pivot.is_preferred" class="text-green-600 text-xs">✓</span>
                                <span v-else class="text-gray-300 text-xs">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </BoxComponent>
        </div>
    </div>
</template>

<script setup>
import { ChevronRightIcon, BuildingOfficeIcon, CubeIcon, SwatchIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';

const props = defineProps({
    supplier: { type: Object, required: true },
})

const form = useForm({
    name:           props.supplier.name,
    email:          props.supplier.email,
    phone:          props.supplier.phone,
    mobile:         props.supplier.mobile,
    website:        props.supplier.website,
    contact_person: props.supplier.contact_person,
    address:        props.supplier.address,
    postal_code:    props.supplier.postal_code,
    city:           props.supplier.city,
    country:        props.supplier.country,
    iban:           props.supplier.iban,
    vat_number:     props.supplier.vat_number,
    kvk_number:     props.supplier.kvk_number,
})

watch([
    () => form.name,
    () => form.email,
    () => form.phone,
    () => form.mobile,
    () => form.website,
    () => form.contact_person,
    () => form.address,
    () => form.postal_code,
    () => form.city,
    () => form.country,
    () => form.iban,
    () => form.vat_number,
    () => form.kvk_number,
], () => {
    form.patch(`/suppliers/${props.supplier.id}`, { preserveScroll: true })
})
</script>
