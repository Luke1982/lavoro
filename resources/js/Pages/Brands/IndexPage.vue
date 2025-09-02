<template>
    <div class="p-4 sm:px-6 lg:px-8 bg-white rounded-md">
        <div class="sm:flex justify-between text-gray-900 flex-wrap">
            <div class="sm:flex-auto lg:min-w-[32rem]">
                <h1 class="text-base font-semibold">Merken</h1>
                <p class="mt-2 text-sm text-gray-700">Hieronder een lijst van alle merken</p>
                <div class="flex items-center flex-wrap gap-x-2 gap-y-2">
                    <div v-if="addingBrand === false" @click="addingBrand = true;"
                        class="border border-green-900 text-green-900 bg-green-100 text-sm p-2 rounded-md cursor-pointer">
                        <PlusCircleIcon class="h-6 w-6 cursor-pointer inline" />
                        Voeg merk toe
                    </div>
                </div>
            </div>
            <div class="mt-4 sm:mt-0 sm:flex-none w-full lg:w-7/12 flex-grow">
                <div>
                    <label class="block text-sm/6 font-medium text-gray-900">Zoek binnen merken</label>
                    <div class="mt-2 flex rounded-md">
                        <div class="relative flex flex-grow items-stretch focus-within:z-10">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <MagnifyingGlassIcon v-if="!inAction" class="h-5 w-5 text-gray-400"
                                    aria-hidden="true" />
                                <ArrowPathIcon v-else class="h-5 w-5 text-gray-400 animate-spin" aria-hidden="true" />
                            </div>
                            <input type="text"
                                :class="[inAction ? 'bg-gray-100' : '', 'block w-full rounded-md border-0 py-1.5 pl-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6']"
                                placeholder="bijv. 'Verloop'" v-model="searchTerm" id="searchInput"
                                :disabled="inAction" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex-wrap flex justify-between gap-4 my-4 rounded-md p-6 ring-gray-300 ring relative items-top pb-14"
            v-if="addingBrand">
            <TextInput v-model="newBrandForm.name" label="Naam" class="flex-grow" :hasError="newBrandForm.errors.name"
                :errorMessage="newBrandForm.errors.name" />
            <div class="absolute bottom-2 right-6">
                <button @click="addNewBrand"
                    class="inline-flex items-center px-4 py-2 ml-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Toevoegen
                </button>
            </div>
            <XCircleIcon class="absolute top-2 right-2 h-8 w-8 text-gray-400 cursor-pointer"
                @click="newBrandForm.reset(); addingBrand = false;" />
        </div>
        <PaginationComponent v-if="internalBrands.length > 0" :paginator="brands" :params="{ search: searchTerm }"
            class="border-b border-gray-200 pb-2" />
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
        <div v-else class="text-center text-gray-500 mt-4">
            <p>Geen merken gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw merk toe.</p>
        </div>
    </div>
</template>
<script setup>
import { ArrowPathIcon, FingerPrintIcon, MagnifyingGlassIcon, PlusCircleIcon, TrashIcon, XCircleIcon } from '@heroicons/vue/24/outline';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import debounce from 'lodash/debounce';
import TextInput from '@/Components/UI/TextInput.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';

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

const addingBrand = ref(false);
const inAction = ref(false);
const newBrandForm = useForm(
    {
        name: '',
    }
)

const addNewBrand = () => {
    newBrandForm.post('brands', {
        preserveScroll: true,
        onSuccess: () => {
            const newBrand = usePage().props.flash.extra;
            internalBrands.value.push({
                id: newBrand.id,
                name: newBrand.name,
                open: false,
            });
            internalBrands.value.sort((a, b) => a.name.localeCompare(b.name));
            newBrandForm.reset();
            addingBrand.value = false;
        },
    });
};

const deleteBrand = (id) => {
    if (!confirm('Weet je zeker dat je dit merk wilt verwijderen?')) {
        return;
    }

    internalBrands.value = internalBrands.value.filter(b => b.id !== id);

    newBrandForm.delete(`brands/${id}`, {
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
