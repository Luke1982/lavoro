<template>
    <component :is="bare ? 'div' : BoxComponent" :class="bare ? '' : 'mt-4'">
        <div v-if="!bare" class="flex items-center mb-3">
            <PuzzlePieceIcon class="size-6 text-gray-500 mr-2" />
            <h3 class="text-sm font-semibold">Nieuwe machine toevoegen</h3>
        </div>
        <div class="grid grid-cols-1 gap-3">
            <ComboBox v-if="showCustomer" :options="allCustomers" v-model="assetForm.customer_id"
                placeholder="Selecteer klant" />
            <p v-if="showCustomer && assetForm.errors.customer_id" class="text-xs text-red-600">{{
                assetForm.errors.customer_id }}</p>

            <ComboBox v-if="showProduct" :options="productOptions" v-model="assetForm.product_id"
                placeholder="Selecteer product" />
            <p v-if="showProduct && assetForm.errors.product_id" class="text-xs text-red-600">{{
                assetForm.errors.product_id }}</p>

            <TextInput v-model="assetForm.serial_number" placeholder="Serienummer"
                :class="assetForm.errors.serial_number ? 'border-red-500' : ''" />
            <p v-if="assetForm.errors.serial_number" class="text-xs text-red-600">{{ assetForm.errors.serial_number }}
            </p>

            <template v-if="requiredParts.length">
                <div class="mt-3 border-t pt-3">
                    <p class="text-xs font-medium text-gray-500 mb-2">
                        Serienummers vereiste onderdelen
                    </p>
                    <div v-for="(part, i) in requiredParts" :key="part.productable_id" class="mb-2">
                        <label class="block text-xs text-gray-500 mb-1">
                            {{ part.relation_name }}: {{ part.name }}
                            <span v-if="part.quantity > 1">(×{{ part.quantity }})</span>
                        </label>
                        <TextInput
                            v-model="assetForm.child_assets[i].serial_number"
                            :placeholder="'Serienummer ' + part.name"
                            class="w-full"
                        />
                    </div>
                </div>
            </template>

            <TextInput v-model="assetForm.next_service_date" type="date" placeholder="Volgende keuringsdatum"
                :class="assetForm.errors.next_service_date ? 'border-red-500' : ''" />
            <p v-if="assetForm.errors.next_service_date" class="text-xs text-red-600">{{
                assetForm.errors.next_service_date }}</p>

            <div class="flex items-center gap-2">
                <SwitchComponent v-model="assetForm.is_active" />
                <label class="text-sm">Actief</label>
            </div>
            <p v-if="assetForm.errors.is_active" class="text-xs text-red-600">{{ assetForm.errors.is_active }}</p>
            <button @click="createAsset"
                class="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                Machine toevoegen
            </button>
        </div>
    </component>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import { PuzzlePieceIcon } from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const emit = defineEmits(['created']);

const props = defineProps({
    allCustomers: {
        type: Array,
        default: () => []
    },
    allProducts: {
        type: Array,
        default: () => []
    },
    customerId: {
        type: [Number, String],
        default: null
    },
    productId: {
        type: [Number, String],
        default: null
    },
    requiredProductablesByProduct: {
        type: Object,
        default: () => ({})
    },
    bare: {
        type: Boolean,
        default: false,
    },
});

const showCustomer = computed(() => !props.customerId);
const showProduct = computed(() => !props.productId);

const productOptions = computed(() => {
    return (props.allProducts || []).map(p => ({
        id: p.id,
        name: `${p.brand?.name ?? ''} ${p.model} (${p.product_type?.name ?? ''})`.trim()
    }));
});

const assetForm = useForm({
    product_id: props.productId ?? null,
    customer_id: props.customerId ?? null,
    serial_number: '',
    next_service_date: null,
    is_active: true,
    child_assets: [],
});

const requiredParts = computed(() => {
    const pid = assetForm.product_id ?? props.productId
    if (!pid) return []
    return props.requiredProductablesByProduct[pid] ?? []
})

watch(() => assetForm.product_id, () => {
    assetForm.child_assets = requiredParts.value.map(part => ({
        productable_id: part.productable_id,
        serial_number: '',
    }))
}, { immediate: true })

const createAsset = () => {
    assetForm.post('/assets', {
        preserveScroll: true,
        onSuccess: () => emit('created'),
    });
};
</script>
