<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex">
                    <PuzzlePieceIcon class="w-6 h-6 text-gray-500 mr-2" />
                    <h1 class="text-l font-bold">Details van de machine</h1>
                </div>
                <div class="flex flex-wrap mt-4 gap-y-3">
                    <div class="w-1/2 flex">
                        <div class="w-1/3 text-xs">Merk en model</div>
                        <div class="w-2/3">
                            {{ asset.product.brand.name }} {{ asset.product.model }}
                        </div>
                    </div>
                    <div class="w-1/2 flex">
                        <div class="w-1/3 text-xs">Serienummer</div>
                        <div class="w-2/3">
                            {{ asset.serial_number }}
                        </div>
                    </div>
                    <div class="w-1/2 flex">
                        <div class="w-1/3 text-xs">Verloopdatum</div>
                        <div class="w-2/3">
                            {{ nlDate(asset.next_service_date) }}
                        </div>
                    </div>
                    <div class="w-1/2 flex">
                        <div class="w-1/3 text-xs">Status</div>
                        <div class="w-2/3">
                            <span v-if="asset.status === 'Actief'"
                                class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                            <span v-else
                                class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{
                                    asset.status }}</span>
                        </div>
                    </div>
                    <div class="w-1/2 flex">
                        <div class="w-1/3 text-xs">Klant</div>
                        <div class="w-2/3">
                            <Link :href="`/customers/${asset.customer.id}`" class="text-blue-600 underline">
                            {{ asset.customer.name }}
                            </Link>
                        </div>
                    </div>
                </div>
            </BoxComponent>
            <BoxComponent class="mt-5">
                <div class="flex">
                    <ExclamationCircleIcon class="w-6 h-6 text-gray-500 mr-2" />
                    <h1 class="text-l font-bold">Storingen</h1>
                </div>
                <TicketCard v-for="ticket in asset.tickets" :key="ticket.id" :ticket="ticket" class="mt-4" />
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent>
                <h2 class="text-center border-b border-gray-300 pb-2 mb-2">
                    <PuzzlePieceIcon class="w-6 h-6 text-gray-500 mr-2 inline-block" />
                    Foto's van de machine:
                </h2>
                <ImageUploadComponent :existing="asset.images" :imageable-id="asset.id"
                    imageable-type="\App\Models\Asset" />
            </BoxComponent>
            <BoxComponent v-if="asset.product.images.length > 0" class="mt-6">
                <h2 class="text-center border-b border-gray-300 pb-2 mb-2">
                    <CubeIcon class="w-6 h-6 text-gray-500 mr-2 inline-block" />
                    Foto's van het
                    <Link :href="`/products/${asset.product.id}`" class="text-blue-600 underline">
                    product
                    </Link>:
                </h2>
                <div class="grid grid-cols-2 gap-6 items-center mt-4">
                    <img v-for="image in asset.product.images" :key="image.id" :src="`/storage/${image.path}`"
                        alt="{{ image.name }}" class="w-full h-auto rounded-lg mb-4" />
                </div>
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { CubeIcon, ExclamationCircleIcon, PuzzlePieceIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { nlDate } from '@/Utilities/Utilities';
import TicketCard from '@/Components/TicketCard.vue';

defineProps({
    asset: {
        type: Object,
        required: true,
    },
});

</script>