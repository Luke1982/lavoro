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
                <div class="mt-4">
                    <div class="flex">
                        <h3 class="text-sm font-semibold mb-3">Beschrijving</h3>
                        <component :is="currentIcon"
                            :class="[editing ? 'text-green-700' : 'text-gray-600', 'ml-2 inline h-5 w-5 mr-2 cursor-pointer']"
                            @click="editing = !editing" />
                    </div>
                    <p class="text-gray-600" v-if="!editing">{{ form.description }}</p>
                    <textarea v-if="editing" v-model="form.description" class="w-full p-2 border rounded"></textarea>
                </div>
            </BoxComponent>
        </template>
        <template #sidebar>
            <ImageUploadComponent :existing="product.images" :imageable-id="product.id"
                imageable-type="\App\Models\Product" />
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ImageUploadComponent from '@/Components/UI/ImageUploadComponent.vue';
import { CubeIcon, PencilSquareIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: {
        type: Object,
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
    origin: 'showPage'
});

watch(editing, (newValue) => {
    if (!newValue) {
        form.patch(`/products/${props.product.id}`);
    }
});

const currentIcon = computed(() =>
    editing.value ? CheckCircleIcon : PencilSquareIcon
);
</script>