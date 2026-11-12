<template>
    <component :is="bare ? 'div' : BoxComponent" :class="bare ? '' : 'mt-4'">
        <div v-if="!bare" class="flex items-center mb-3">
            <PuzzlePieceIcon class="size-6 text-gray-500 mr-2" />
            <h3 class="text-sm font-semibold">Nieuwe machine toevoegen</h3>
        </div>
        <div>
            <div class="p-6 grid grid-cols-1 gap-3">
                <CustomerPicker v-if="showCustomer" :options="customerComboOptions" v-model="assetForm.customer_id"
                    :has-external-searching="customersUseAjax" :searching="customerSearching"
                    @change="searchCustomers" @created="option => customerComboOptions.unshift(option)"
                    :has-error="Boolean(assetForm.errors.customer_id)" :error-message="assetForm.errors.customer_id" />

                <ProductPicker v-if="showProduct" :options="productComboOptions" v-model="assetForm.product_id"
                    :has-external-searching="productsUseAjax" :searching="productSearching"
                    @change="searchProducts" @created="option => productComboOptions.unshift(option)"
                    :has-error="Boolean(assetForm.errors.product_id)" :error-message="assetForm.errors.product_id" />

                <div class="flex items-start gap-2">
                    <TextInput v-model="assetForm.serial_number" class="flex-1" :placeholder="serialPlaceholder"
                        :disabled="!selectedNeedsSerial" :has-error="!!assetForm.errors.serial_number"
                        :error-message="assetForm.errors.serial_number ?? ''" />
                    <ScanSerialButton :disabled="!selectedNeedsSerial" class="mt-0.5"
                        @picked="assetForm.serial_number = $event" />
                </div>
            </div>
            <div class="border-t border-gray-200 my-3"></div>
            <div class="p-6 grid grid-cols-1 gap-3">
                <Transition v-bind="fadeUp">
                    <BundlePartsFields v-model="assetForm.child_assets" :product-id="assetForm.product_id"
                        :bundle-parts="bundlePartsByProduct" :errors="assetForm.errors" />
                </Transition>

                <TextInput v-model="assetForm.date_in_service" type="date" label="In gebruikname"
                    placeholder="In gebruikname" :has-error="!!assetForm.errors.date_in_service"
                    :error-message="assetForm.errors.date_in_service ?? ''" />

                <TextInput v-model="assetForm.next_service_date" type="date" label="Volgende keuring"
                    placeholder="Volgende keuring" :has-error="!!assetForm.errors.next_service_date"
                    :error-message="assetForm.errors.next_service_date ?? ''" />

                <div class="flex items-center gap-2">
                    <SwitchComponent v-model="assetForm.is_active" />
                    <label class="text-sm">Actief</label>
                </div>
                <p v-if="assetForm.errors.is_active" class="text-xs text-red-600">{{ assetForm.errors.is_active }}</p>
                <button @click="createAsset"
                    class="px-3 py-2 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90">
                    Machine toevoegen
                </button>
            </div>
        </div>
    </component>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import ScanSerialButton from '@/Components/UI/ScanSerialButton.vue';
import BundlePartsFields from '@/Components/BundlePartsFields.vue';
import ProductPicker from '@/Components/Products/ProductPicker.vue';
import CustomerPicker from '@/Components/Customers/CustomerPicker.vue';
import { PuzzlePieceIcon } from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { todayIso, nextServiceIso } from '@/Utilities/Utilities';
import { fadeUp } from '@/Utilities/transitions';
import { useComboSearch } from '@/Composables/useComboSearch';

const emit = defineEmits(['created']);

const props = defineProps({
    allCustomers: {
        type: Array,
        default: () => []
    },
    customersUseAjax: { type: Boolean, default: false },
    allProducts: {
        type: Array,
        default: () => []
    },
    productsUseAjax: { type: Boolean, default: false },
    customerId: {
        type: [Number, String],
        default: null
    },
    productId: {
        type: [Number, String],
        default: null
    },
    bundlePartsByProduct: {
        type: Object,
        default: () => ({})
    },
    bare: {
        type: Boolean,
        default: false,
    },
    isBundle: {
        type: Boolean,
        default: null,
    },
    isRegistable: {
        type: Boolean,
        default: null,
    },
    productTypicalDays: {
        type: Number,
        default: null,
    },
    productTypeTypicalDays: {
        type: Number,
        default: null,
    },
});

const showCustomer = computed(() => !props.customerId);
const showProduct = computed(() => !props.productId);

const initialProductOptions = (props.allProducts || []).map(p => ({
    id: p.id,
    name: `${p.brand?.name ?? ''} ${p.model} (${p.product_type?.name ?? ''})`.trim(),
    bundle: p.bundle,
    registable: p.registable,
    typical_certificate_days: p.typical_certificate_days,
    product_type_typical_certificate_days: p.product_type_typical_certificate_days,
}))

const { options: productComboOptions, searching: productSearching, search: searchProducts } =
    useComboSearch('products', initialProductOptions, props.productsUseAjax)

const { options: customerComboOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const selectedProduct = computed(() =>
    productComboOptions.value.find(p => p.id === assetForm.product_id) ?? null
);

const isSelectedBundle = computed(() => {
    if (props.isBundle !== null) return props.isBundle;
    return selectedProduct.value?.bundle === true;
});

/**
 * A bundel is a container and a product that is not registreerbaar is counted rather than
 * written down, so neither offers a serienummer field.
 */
const selectedNeedsSerial = computed(() => {
    if (isSelectedBundle.value) return false;
    if (props.isRegistable !== null) return props.isRegistable;
    return selectedProduct.value?.registable !== false;
});

const serialPlaceholder = computed(() => {
    if (isSelectedBundle.value) return 'Dit is een gebundeld product, hier kan geen serienummer voor ingevoerd worden';
    if (!selectedNeedsSerial.value) return 'Dit product wordt niet op serienummer geregistreerd';
    return 'Serienummer';
});

function resolveNextServiceDate(productId) {
    const product = productComboOptions.value.find(p => p.id === productId)
    const fallback = props.productTypicalDays ?? props.productTypeTypicalDays ?? 365
    return nextServiceIso(product, fallback)
}

const assetForm = useForm({
    product_id: props.productId ?? null,
    customer_id: props.customerId ?? null,
    serial_number: '',
    date_in_service: todayIso(),
    next_service_date: resolveNextServiceDate(props.productId ?? null),
    is_active: true,
    child_assets: [],
});

watch(() => assetForm.product_id, (productId) => {
    assetForm.serial_number = ''
    assetForm.next_service_date = resolveNextServiceDate(productId)
})

const createAsset = () => {
    assetForm.post('/assets', {
        preserveScroll: true,
        onSuccess: () => emit('created'),
    });
};
</script>
