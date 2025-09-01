<template>
    <BoxComponent class="mb-3">
        <div class="flex justify-between flex-wrap md:flex-nowrap">
            <nav class="flex justify-around md:justify-between px-4 pb-2 sm:px-0 w-full md:w-1/2"
                v-if="links.length > 3">
                <div class="md:flex space-x-1">
                    <Link v-for="link in links" :key="link.url"
                        :href="`${link.url}${searchForm.search ? `&search=${searchForm.search}` : ''}`"
                        class="px-3 py-1 text-sm font-medium border rounded hover:border-gray-300 hover:text-gray-700"
                        :class="[link.active
                            ? 'border-indigo-500 text-indigo-600'
                            : 'border-transparent text-gray-500', link.url ? '' : 'hidden']" preserve-scroll>
                    <span v-html="decodeEntities(link.label)"></span>
                    </Link>
                </div>
            </nav>
            <div class="w-1/2 relative flex-grow flex flex-wrap md:flex-nowrap ml-0 md:ml-4 mt-3 md:mt-0">
                <SearchComponent v-model="searchForm.search" url="/assets" label=""
                    placeholder="Zoek op merk, model, soort of klant" input-id="searchInput" />
                <ComboBox class="ml-0 md:ml-2 w-full mt-3 md:mt-0" :options="statusOptions" v-model="selectedStatus"
                    placeholder="Laat alleen status zien"
                    @update:modelValue="val => { updateLocalStorageStatus(val) }" />
            </div>
        </div>
    </BoxComponent>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <ul role="list"
            class="divide-y divide-gray-100 overflow-hidden bg-white shadow-xs ring-1 ring-gray-900/5 sm:rounded-xl">
            <li v-for="asset in filteredAssets" :key="asset.id"
                class="relative flex flex-col md:flex-row justify-between gap-x-6 px-4 py-5 hover:bg-slate-100 sm:px-6">
                <div class="flex min-w-0 gap-x-4">
                    <img class="size-12 flex-none rounded-full bg-gray-50"
                        :src="asset.product.images.length > 0 ? `/storage/${asset.product.images[0].path}` : ''"
                        alt="" />
                    <div class="min-w-0 flex-auto">
                        <p class="text-sm/6 font-semibold text-gray-900">
                            <Link :href="`/assets/${asset.id}`">
                            <span class="absolute inset-x-0 -top-px bottom-0" />
                            {{ asset.product.brand.name }} {{ asset.product.model }}
                            </Link>
                        </p>
                        <p class="mt-1 flex text-xs/5 text-gray-500">
                            <Link :href="`/producttypes?search=${asset.product.product_type.name}`"
                                class="relative truncate underline">{{
                                    asset.product.product_type.name }}</Link>
                            &nbsp;bij&nbsp;
                            <Link :href="`/customers/${asset.customer.id}`" class="relative truncate underline">{{
                                asset.customer.name }}</Link>
                            &nbsp;in&nbsp;{{ asset.customer.city }}
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-x-4 pl-15 md:pl-0 mt-3 md:mt-0">
                    <div class="flex md:flex-col md:items-end">
                        <span v-if="asset.status === 'Actief'"
                            class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                        <span v-else
                            class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{
                                asset.status }}</span>
                        <p v-if="asset.next_service_date"
                            class="mt-1 text-xs/5 text-gray-500 flex items-center ml-3 md:ml-0">
                            <CalendarDateRangeIcon class="inline-block size-5 text-gray-400 mr-2" aria-hidden="true" />
                            <time :datetime="asset.next_service_date">{{ new
                                Date(asset.next_service_date).toLocaleDateString('nl-NL', {
                                    year: 'numeric',
                                    month: '2-digit',
                                    day: '2-digit',
                                }) }}</time>
                        </p>
                    </div>
                    <ChevronRightIcon
                        class="size-5 flex-none text-gray-400 absolute md:relative right-6 top-1/2 -translate-y-1/2 md:translate-y-0 md:top-auto md:right-auto md:ml-3"
                        aria-hidden="true" />
                </div>
            </li>
        </ul>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import { computed, ref } from 'vue';
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import { CalendarDateRangeIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import SearchComponent from '@/Components/UI/SearchComponent.vue';

const props = defineProps({
    assets: {
        type: Object,
        required: true,
    },
    initialSearch: { type: String, default: '' },
});

const searchForm = useForm({
    search: props.initialSearch,
});

const links = computed(() => props.assets.links);
const selectedStatus = ref(Number(localStorage.getItem('selectedAssetStatus')) || 1);
const filteredAssets = computed(() => {
    if (selectedStatus.value === 1) {
        return props.assets.data;
    }
    return props.assets.data.filter(asset => {
        return asset.status === (selectedStatus.value === 2 ? 'Actief' : 'Niet actief');
    });
});

const statusOptions = [
    { id: 1, name: 'Alle' },
    { id: 2, name: 'Actief' },
    { id: 3, name: 'Niet actief' },
];

function updateLocalStorageStatus(val) {
    localStorage.setItem('selectedAssetStatus', val);
}

function decodeEntities(str) {
    const txt = document.createElement('textarea')
    txt.innerHTML = str
    return txt.value
}
</script>