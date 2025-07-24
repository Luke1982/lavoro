<template>
    <BoxComponent>
        <h1 class="text-2xl font-bold mb-4 text-center">
            Keuring voor {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
        </h1>
        <div class="grid grid-cols-12 gap-y-2 border-b border-gray-200 pb-4">
            <div class="col-span-2 text-xs">
                Naam klant
            </div>
            <div class="col-span-4">
                <Link :href="`/customers/${servicejob.asset.customer.id}`" class="underline">
                {{ servicejob.asset.customer.name }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Werkbon
            </div>
            <div class="col-span-4">
                <Link :href="`/serviceorders/${servicejob.service_order.id}`" class="underline">
                Nummer {{ servicejob.service_order.id }} gemaakt op {{ nlDate(servicejob.service_order.created_at) }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Naam contactpersoon
            </div>
            <div class="col-span-4">
                {{ servicejob.service_order.signed_by }}
            </div>
            <div class="col-span-2 text-xs">
                Soort product
            </div>
            <div class="col-span-4">
                <Link :href="`/producttypes?search=${servicejob.asset.product.product_type.name}`" class="underline">
                {{ servicejob.asset.product.product_type.name }}
                </Link>
            </div>
        </div>
        <h1 class="text-xl font-bold my-4 text-center">
            Keurpunten
        </h1>
        <div class="flex flex-wrap">
            <ServiceCheckInstanceComponent v-for="check in servicejob.check_instances" :key="check.id"
                :service-check-instance="check" :check-types-with-options="checkTypesWithOptions" class="w-1/3" />
        </div>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ServiceCheckInstanceComponent from '@/Components/ServiceCheckInstanceComponent.vue';
import { nlDate } from '@/Utilities/Utilities';
import { Link } from '@inertiajs/vue3';

defineProps({
    servicejob: {
        type: Object,
        required: true
    },
    checkTypesWithOptions: {
        type: Array,
        required: true
    }
});

</script>