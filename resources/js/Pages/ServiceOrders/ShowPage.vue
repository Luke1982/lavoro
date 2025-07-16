<template>
    <BoxComponent>
        <h1 class="text-2xl font-bold mb-4 text-center uppercase">Werkbon</h1>
        <div class="grid grid-cols-12 gap-y-2">
            <div class="col-span-2 text-xs">
                Naam klant
            </div>
            <div class="col-span-4">
                <Link :href="`/customers/${serviceOrder.customer.id}`" class="underline">
                {{ serviceOrder.customer.name }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Adres
            </div>
            <div class="col-span-4">
                <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank" class="underline">{{
                    serviceOrder.customer.address
                }}, {{
                        serviceOrder.customer.postal_code }} {{
                        serviceOrder.customer.city }}
                </a>
            </div>
            <div class="col-span-2 text-xs">
                Naam contactpersoon
            </div>
            <div class="col-span-4">
                <EditableTextField :value="form.signed_by" class="w-full"
                    @update="val => { form.signed_by = val; updateServiceOrder(); }" />
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import { mapsLinkFromCustomer } from '@/Utilities/Utilities';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    }
});

const form = useForm({
    ...props.serviceOrder
});

const updateServiceOrder = () => {
    form.put(`/serviceorders/${props.serviceOrder.id}`, {
        preserveScroll: true,
    });
};
</script>