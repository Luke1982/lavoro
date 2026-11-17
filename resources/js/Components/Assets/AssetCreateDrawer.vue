<template>
    <DrawerComponent v-if="canCreate" v-model="open" title="Nieuwe machine toevoegen"
        subtitle="Vul onderstaande velden in om een nieuwe machine toe te voegen.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <Field label="Product">
                <p v-if="product" :class="fixedValueClass">{{ product.name }}</p>
                <ProductPicker v-else v-model="form.product_id" :options="productOptions"
                    :has-external-searching="productsUseAjax" :searching="productSearching"
                    :has-error="Boolean(form.errors.product_id)" :error-message="form.errors.product_id"
                    @change="searchProducts" @created="option => productOptions.unshift(option)" />
            </Field>
            <Field label="Klant">
                <p v-if="customer" :class="fixedValueClass">{{ customer.name }}</p>
                <CustomerPicker v-else v-model="form.customer_id" :options="customerOptions"
                    :has-external-searching="customersUseAjax" :searching="customerSearching"
                    :has-error="Boolean(form.errors.customer_id)" :error-message="form.errors.customer_id"
                    @change="searchCustomers" @created="option => customerOptions.unshift(option)" />
            </Field>
            <Field v-if="location" label="Locatie">
                <p :class="fixedValueClass">{{ location.title }}</p>
            </Field>
            <Transition v-else v-bind="fadeUp">
                <Field v-if="form.customer_id" label="Locatie">
                    <ComboBox v-model="form.location_id" :options="locationOptions" :empty-message="locationMessage"
                        placeholder="Geen locatie" :has-error="Boolean(form.errors.location_id)"
                        :error-message="form.errors.location_id" />
                </Field>
            </Transition>
            <Field label="Serienummer">
                <div class="flex items-start gap-2">
                    <TextInput v-model="form.serial_number" class="flex-1" :placeholder="serialPlaceholder"
                        :disabled="!needsSerial" :has-error="Boolean(form.errors.serial_number)"
                        :error-message="form.errors.serial_number" />
                    <ScanSerialButton :disabled="!needsSerial" class="mt-0.5" @picked="form.serial_number = $event" />
                </div>
            </Field>
            <Transition v-bind="fadeUp">
                <BundlePartsFields v-model="form.child_assets" :product-id="form.product_id"
                    :bundle-parts="bundlePartsByProduct" :errors="form.errors" class="px-4 sm:px-6 py-4" />
            </Transition>
            <Field label="In gebruikname">
                <TextInput v-model="form.date_in_service" type="date" :has-error="Boolean(form.errors.date_in_service)"
                    :error-message="form.errors.date_in_service" />
            </Field>
            <Field label="Volgende keuring">
                <TextInput v-model="form.next_service_date" type="date"
                    :has-error="Boolean(form.errors.next_service_date)" :error-message="form.errors.next_service_date" />
            </Field>
            <Field label="Actief">
                <SwitchComponent v-model="form.is_active" />
            </Field>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="close"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Toevoegen
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import Field from '@/Components/UI/DrawerField.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import ScanSerialButton from '@/Components/UI/ScanSerialButton.vue';
import BundlePartsFields from '@/Components/BundlePartsFields.vue';
import ProductPicker from '@/Components/Products/ProductPicker.vue';
import CustomerPicker from '@/Components/Customers/CustomerPicker.vue';
import { useComboSearch } from '@/Composables/useComboSearch';
import { useCustomerLocations } from '@/Composables/useCustomerLocations';
import { hasPermission, todayIso, nextServiceIso } from '@/Utilities/Utilities';
import { fadeUp } from '@/Utilities/transitions';

/**
 * The one form for a new machine, wherever it is started. Whatever the page already knows
 * is passed in and shown as fixed: the customer on a customer, the product on a product,
 * customer and location on a location. The rest is picked here, and a product, merk or
 * soort that does not exist yet is made on the spot from the product picker. `product` is
 * shaped as the product picker offers it ({ id, name, bundle, registable, ... }).
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    customer: { type: Object, default: null },
    location: { type: Object, default: null },
    product: { type: Object, default: null },
    allProducts: { type: Array, default: () => [] },
    productsUseAjax: { type: Boolean, default: false },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    bundlePartsByProduct: { type: Object, default: () => ({}) },
});

const canCreate = hasPermission('asset.create');
const fixedValueClass = 'text-sm text-gray-700 dark:text-slate-300';

const { options: productOptions, searching: productSearching, search: searchProducts } =
    useComboSearch('products', props.allProducts, props.productsUseAjax);

const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax);

const { locations: locationOptions, load: loadLocations, message: locationMessage } = useCustomerLocations();

const form = useForm({
    product_id: props.product?.id ?? null,
    customer_id: props.customer?.id ?? null,
    location_id: props.location?.id ?? null,
    serial_number: '',
    date_in_service: todayIso(),
    next_service_date: props.product ? nextServiceIso(props.product) : '',
    is_active: true,
    child_assets: [],
});

const selectedProduct = computed(() =>
    props.product ?? productOptions.value.find(option => option.id === form.product_id) ?? null
);

/**
 * A bundel is a container and a product that is not registreerbaar is counted rather than
 * written down, so neither offers a serienummer field.
 */
const needsSerial = computed(() =>
    Boolean(selectedProduct.value) && !selectedProduct.value.bundle && selectedProduct.value.registable !== false
);

const serialPlaceholder = computed(() => {
    if (!selectedProduct.value) return 'Kies eerst een product';
    if (selectedProduct.value.bundle) return 'Bundel — geen serienummer';
    if (!needsSerial.value) return 'Dit product wordt niet op serienummer geregistreerd';
    return 'Serienummer';
});

watch(() => form.product_id, () => {
    form.serial_number = '';
    form.next_service_date = selectedProduct.value ? nextServiceIso(selectedProduct.value) : '';
});

/**
 * Fetched every time the drawer opens rather than once, so a list that failed to load gets
 * another try and a location made in the meantime shows up.
 */
function offerLocations() {
    if (open.value && !props.location) loadLocations(form.customer_id);
}

watch(open, offerLocations);

watch(() => form.customer_id, () => {
    if (props.location) return;
    form.location_id = null;
    offerLocations();
});

function close() {
    open.value = false;
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post('/assets', {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>
