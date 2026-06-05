<template>
    <component :is="bare ? 'div' : BoxComponent" :class="bare ? '' : 'mt-4'">
        <div v-if="!bare" class="flex items-center mb-3">
            <PuzzlePieceIcon class="size-6 text-gray-500 mr-2" />
            <h3 class="text-sm font-semibold">Nieuwe machine toevoegen</h3>
        </div>
        <div>
            <div class="p-6 grid grid-cols-1 gap-3">
                <ComboBox v-if="showCustomer" :options="customerComboOptions" v-model="assetForm.customer_id"
                    :has-external-searching="customersUseAjax" :searching="customerSearching"
                    @change="searchCustomers" placeholder="Selecteer klant" />
                <p v-if="showCustomer && assetForm.errors.customer_id" class="text-xs text-red-600">{{
                    assetForm.errors.customer_id }}</p>

                <ComboBox v-if="showProduct" :options="productComboOptions" v-model="assetForm.product_id"
                    :has-external-searching="productsUseAjax" :searching="productSearching"
                    @change="searchProducts" placeholder="Selecteer product" />
                <p v-if="showProduct && assetForm.errors.product_id" class="text-xs text-red-600">{{
                    assetForm.errors.product_id }}</p>

                <TextInput v-model="assetForm.serial_number"
                    :placeholder="isSelectedBundle ? 'Dit is een gebundeld product, hier kan geen serienummer voor ingevoerd worden' : 'Serienummer'"
                    :disabled="isSelectedBundle" :has-error="!!assetForm.errors.serial_number"
                    :error-message="assetForm.errors.serial_number ?? ''" />
            </div>
            <div class="border-t border-gray-200 my-3"></div>
            <div class="p-6 grid grid-cols-1 gap-3">
                <Transition enter-active-class="transition-all duration-300 ease-out"
                    enter-from-class="opacity-0 translate-y-2" enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 translate-y-2">
                    <div v-if="requiredParts.length">
                        <p class="text-xs font-medium text-gray-500 mb-2">
                            Serienummers vereiste onderdelen
                        </p>
                        <div v-for="slot in childAssetSlots" :key="slot.idx" class="mb-4">
                            <label class="block text-xs text-gray-500 mb-1">
                                {{ slot.part.relation_name }}: {{ slot.part.name }}
                                <span v-if="slot.part.quantity > 1">({{ slot.q + 1 }}/{{ slot.part.quantity }})</span>
                            </label>
                            <TextInput v-model="assetForm.child_assets[slot.idx].serial_number"
                                :placeholder="'Serienummer ' + slot.part.name" class="w-full"
                                :has-error="!!assetForm.errors[`child_assets.${slot.idx}.serial_number`]"
                                :error-message="assetForm.errors[`child_assets.${slot.idx}.serial_number`] ?? ''" />
                        </div>
                    </div>
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
                    class="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                    Machine toevoegen
                </button>
            </div>
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
import { todayIso, nextServiceIso } from '@/Utilities/Utilities';
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
    requiredProductablesByProduct: {
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
    typical_certificate_days: p.typical_certificate_days,
    product_type_typical_certificate_days: p.product_type_typical_certificate_days,
}))

const { options: productComboOptions, searching: productSearching, search: searchProducts } =
    useComboSearch('products', initialProductOptions, props.productsUseAjax)

const { options: customerComboOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const isSelectedBundle = computed(() => {
    if (props.isBundle !== null) return props.isBundle;
    const pid = assetForm.product_id;
    const product = productComboOptions.value.find(p => p.id === pid);
    return product?.bundle === true;
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

const requiredParts = computed(() => {
    const pid = assetForm.product_id ?? props.productId
    if (!pid) return []
    return props.requiredProductablesByProduct[pid] ?? []
})

const childAssetSlots = computed(() => {
    let idx = 0
    return requiredParts.value.flatMap(part =>
        Array.from({ length: part.quantity }, (_, q) => ({ part, idx: idx++, q }))
    )
})

watch(() => assetForm.product_id, (productId) => {
    assetForm.serial_number = ''
    assetForm.next_service_date = resolveNextServiceDate(productId)
    assetForm.child_assets = requiredParts.value.flatMap(part =>
        Array.from({ length: part.quantity }, () => ({
            productable_id: part.productable_id,
            serial_number: '',
        }))
    )
}, { immediate: true })

const createAsset = () => {
    assetForm.post('/assets', {
        preserveScroll: true,
        onSuccess: () => emit('created'),
    });
};
</script>
