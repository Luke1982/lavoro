<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Merken" subtitle="Hieronder een lijst van alle merken" v-model="searchTerm"
            :in-action="inAction" search-label="Zoek binnen merken" search-placeholder="bijv. 'Verloop'"
            add-label="Voeg merk toe" :paginator="brands" :pagination-params="{ search: searchTerm }"
            @add="() => brandFormRef?.show()" />
    </div>

    <!-- Form box -->
    <div v-auto-animate class="mb-4">
        <CreateRecordForm ref="brandFormRef" external-trigger action="/brands" :fields="brandFields"
            add-button-label="Voeg merk toe" submit-label="Toevoegen" @created="onBrandCreated" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div class="-mx-4 mt-3 sm:-mx-0 max-w-full overflow-x-scroll" v-if="internalBrands.length > 0">
            <table class="min-w-full divide-y divide-gray-300">
                <thead>
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0">
                            Naam</th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-0">
                            <span class="sr-only">Wijzig</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <tr v-for="brand in internalBrands" :key="brand.id">
                        <td class="w-10/12 py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:max-w-none sm:pl-0">
                            <div v-if="brand.open" class="relative flex flex-grow items-stretch focus-within:z-10">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <FingerPrintIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
                                </div>
                                <input type="text"
                                    class="block w-full rounded-md border-0 py-1.5 pl-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6"
                                    placeholder="bijv. 'Verloop'" v-model="brand.name" />
                            </div>
                            <span v-else>{{ brand.name }}</span>
                        </td>
                        <td class="py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-0">
                            <a @click="toggleRecord(brand.id)" :preserve-scroll="true"
                                class="text-indigo-600 hover:text-indigo-900 cursor-pointer">
                                <span v-if="brand.open" class="text-green-500">Opslaan</span>
                                <span v-else>Wijzig</span>
                                <TrashIcon class="ml-2 h-5 w-5 text-red-400 inline" aria-hidden="true"
                                    @click.stop="deleteBrand(brand.id)" />
                                <span class="sr-only">, {{
                                    brand.name }}</span>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationComponent v-if="internalBrands.length > 0" :paginator="brands" :params="{ search: searchTerm }"
            class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen merken gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw merk toe.</p>
        </div>
    </BoxComponent>
</template>
<script setup>
import { FingerPrintIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import debounce from 'lodash/debounce';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';

const brandFormRef = ref(null)

const props = defineProps({
    brands: {
        type: Object,
        required: true,
    },
    search: {
        type: String,
        default: '',
    },
});

const inAction = ref(false);
const brandFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

function onBrandCreated(newBrand) {
    if (!newBrand) return
    internalBrands.value.push({ id: newBrand.id, name: newBrand.name, open: false })
    internalBrands.value.sort((a, b) => a.name.localeCompare(b.name))
}

const deleteBrand = (id) => {
    if (!confirm('Weet je zeker dat je dit merk wilt verwijderen?')) {
        return;
    }

    internalBrands.value = internalBrands.value.filter(b => b.id !== id);

    useForm({}).delete(`brands/${id}`, {
        preserveScroll: true,
    });
};

const internalBrands = ref(props.brands.data);
const searchTerm = ref(props.search);
// pagination handled by PaginationComponent

const toggleRecord = (id) => {
    const newInternalbrands = internalBrands.value.map((brand) => {
        if (brand.open) {
            const updatebrandForm = useForm({
                name: brand.name,
            });
            updatebrandForm.patch(`brands/${brand.id}`, {
                preserveScroll: true,
            });
        }
        brand.open = brand.id === id ? !brand.open : false;
        return brand;
    });
    internalBrands.value = newInternalbrands;
    internalBrands.value.sort((a, b) => a.name.localeCompare(b.name));
};

// Debounced search function
const searchBrands = debounce((term) => {
    inAction.value = true;
    localStorage.setItem('searchInitiated', 'true');
    router.get(`brands?search=${term}`, {}, { preserveScroll: true });
}, 300);

// Watch for changes to searchTerm and call debounced search
watch(searchTerm, (newTerm) => {
    searchBrands(newTerm);
});

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false;
        localStorage.removeItem('searchInitiated');
        document.getElementById('searchInput').focus();
    }
});

</script>
