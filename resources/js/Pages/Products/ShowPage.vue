<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex border-b border-gray-200 items-center">
                    <div class="flex justify-between w-full">
                        <div class="flex">
                            <CubeIcon class="size-12 flex-none object-cover p-2 mb-2" />
                            <div class="flex flex-col">
                                <span class="text-lg">{{ product.brand.name }} {{ product.model }}</span>
                                <span class="text-sm text-gray-400">{{ product.product_type.name }}</span>
                            </div>
                        </div>
                        <div class="flex self-ends">
                            <span class="text-sm text-gray-500 ml-4">Verkocht tussen {{ new
                                Date(product.start_sell).toLocaleDateString('nl-NL', {
                                    day: '2-digit',
                                    month: '2-digit',
                                    year: 'numeric'
                                }) }} en {{
                                    new Date(product.end_sell).toLocaleDateString('nl-NL', {
                                        day: '2-digit', month:
                                            '2-digit',
                                        year: 'numeric'
                                    }) }}</span>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex">
                    <div class="flex flex-col w-1/2">
                        <div class="flex">
                            <h3 class="text-sm font-semibold mb-3">Beschrijving</h3>
                            <component :is="currentIcon"
                                :class="[editing ? 'text-green-700' : 'text-gray-600', 'ml-2 inline h-5 w-5 mr-2 cursor-pointer']"
                                @click="editing = !editing" />
                        </div>
                        <p class="text-gray-600" v-if="!editing">{{ form.description }}</p>
                        <textarea v-if="editing" v-model="form.description"
                            class="w-full p-2 border rounded"></textarea>
                    </div>
                    <div class="w-1/2">
                        <h3 class="text-sm font-semibold mb-3">
                            Typische certificeringstermijn (dagen)
                            <InformationCircleIcon class="inline h-5 w-5 text-gray-400 ml-1 cursor-pointer" v-tooltip="{
                                html: true,
                                content: `<span class='block w-80'>Het standaard aantal dagen waarmee de keuringsdatum van
                                een machine vooruitgeschoven wordt bij een succesvolle keuring. Laat dit leeg om de termijn
                                van het type (${product.product_type.name}, ${product.product_type.typical_certificate_days ?? 0} dagen) te
                                gebruiken. Als je hier iets invult overschrijft dat de waarde die op het type is ingesteld.</span>`
                            }" />
                        </h3>
                        <EditableTextField v-model="form.typical_certificate_days" type="input" input-type="number" />
                    </div>
                </div>
                <div class="flex items-center py-3 border-t border-gray-200 mt-5">
                    <PuzzlePieceIcon class="size-6 text-gray-500" />
                    <h3 class="text-sm font-medium ml-2">Machines</h3>
                </div>
                <AssetListComponent :assets="product.assets" />
            </BoxComponent>
        </template>
        <template #sidebar>
            <ImageUploadComponent :existing="product.images" :imageable-id="product.id"
                imageable-type="\App\Models\Product" />
            <BoxComponent class="mt-4">
                <div class="flex items-center mb-3">
                    <PuzzlePieceIcon class="size-6 text-gray-500 mr-2" />
                    <h3 class="text-sm font-semibold">Nieuwe machine voor dit product</h3>
                </div>
                <div class="grid grid-cols-1 gap-3">
                    <ComboBox :options="allCustomers" v-model="assetForm.customer_id" placeholder="Selecteer klant" />
                    <p v-if="assetForm.errors.customer_id" class="text-xs text-red-600">{{ assetForm.errors.customer_id
                        }}</p>

                    <TextInput v-model="assetForm.serial_number" placeholder="Serienummer"
                        :class="assetForm.errors.serial_number ? 'border-red-500' : ''" />
                    <p v-if="assetForm.errors.serial_number" class="text-xs text-red-600">{{
                        assetForm.errors.serial_number }}</p>

                    <TextInput v-model="assetForm.next_service_date" type="date" placeholder="Volgende keuringsdatum"
                        :class="assetForm.errors.next_service_date ? 'border-red-500' : ''" />
                    <p v-if="assetForm.errors.next_service_date" class="text-xs text-red-600">{{
                        assetForm.errors.next_service_date }}</p>

                    <div class="flex items-center gap-2">
                        <SwitchComponent v-model="assetForm.is_active" />
                        <label class="text-sm">Actief</label>
                    </div>
                    <p v-if="assetForm.errors.is_active" class="text-xs text-red-600">{{ assetForm.errors.is_active }}
                    </p>
                    <button @click="createAsset"
                        class="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                        Machine toevoegen
                    </button>
                </div>
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import { CubeIcon, PencilSquareIcon, CheckCircleIcon, PuzzlePieceIcon, InformationCircleIcon } from '@heroicons/vue/24/outline';
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AssetListComponent from '@/Components/AssetListComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    allCustomers: {
        type: Array,
        required: true
    }
});

const editing = ref(false);
const form = useForm({
    description: props.product.description,
    id: props.product.id,
    model: props.product.model,
    brand_id: props.product.brand.id,
    product_type_id: props.product.product_type.id,
    start_sell: props.product.start_sell,
    end_sell: props.product.end_sell,
    typical_certificate_days: props.product.typical_certificate_days,
    origin: 'showPage'
});

watch([
    () => form.description,
    () => form.typical_certificate_days
], () => {
    form.patch(`/products/${props.product.id}`);
});

const currentIcon = computed(() =>
    editing.value ? CheckCircleIcon : PencilSquareIcon
);

const assetForm = useForm({
    product_id: props.product.id,
    customer_id: props.product.assets[0]?.customer?.id ?? null,
    serial_number: '',
    next_service_date: null,
    is_active: true,
});

const createAsset = () => {
    assetForm.post('/assets', { preserveScroll: true });
};
</script>