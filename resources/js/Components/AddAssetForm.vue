<template>
    <component :is="bare ? 'div' : BoxComponent" :class="bare ? '' : 'mt-4'">
        <div v-if="!bare" class="flex items-center mb-3">
            <PuzzlePieceIcon class="size-6 text-gray-500 mr-2" />
            <h3 class="text-sm font-semibold">Nieuwe machine toevoegen</h3>
        </div>
        <div>
            <div class="p-6 grid grid-cols-1 gap-3">
                <ComboBox v-if="showCustomer" :options="allCustomers" v-model="assetForm.customer_id"
                    placeholder="Selecteer klant" />
                <p v-if="showCustomer && assetForm.errors.customer_id" class="text-xs text-red-600">{{
                    assetForm.errors.customer_id }}</p>

                <ComboBox v-if="showProduct" :options="productOptions" v-model="assetForm.product_id"
                    placeholder="Selecteer product" />
                <p v-if="showProduct && assetForm.errors.product_id" class="text-xs text-red-600">{{
                    assetForm.errors.product_id }}</p>

                <TextInput v-model="assetForm.serial_number" placeholder="Serienummer"
                    :has-error="!!assetForm.errors.serial_number"
                    :error-message="assetForm.errors.serial_number ?? ''" />
            </div>
            <div class="border-t border-gray-200 my-3"></div>
            <div class="p-6 grid grid-cols-1 gap-3">
                <Transition
                    enter-active-class="transition-all duration-300 ease-out"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-2"
                >
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

                <TextInput v-model="assetForm.next_service_date" type="date" placeholder="Volgende keuringsdatum"
                    :has-error="!!assetForm.errors.next_service_date"
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

const childAssetSlots = computed(() => {
    let idx = 0
    return requiredParts.value.flatMap(part =>
        Array.from({ length: part.quantity }, (_, q) => ({ part, idx: idx++, q }))
    )
})

watch(() => assetForm.product_id, () => {
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
