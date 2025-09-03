<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Producttypen" subtitle="Hieronder een lijst van alle producttypen"
            v-model="searchTerm" :in-action="inAction" search-label="Zoek binnen producttypen"
            search-placeholder="bijv. 'Viergastester'" add-label="Voeg producttype toe" :paginator="productTypes"
            :pagination-params="{ search: searchTerm }" @add="() => typeFormRef?.show()" />
    </div>

    <!-- Form box -->
    <div class="mt-0 mb-4" v-auto-animate>
        <CreateRecordForm ref="typeFormRef" external-trigger action="/producttypes" :fields="typeFields"
            add-button-label="Voeg producttype toe" submit-label="Toevoegen" @created="onTypeCreated" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div class="-mx-4 mt-3 sm:-mx-0 max-w-full overflow-x-scroll" v-if="internalTypes.length">
            <table class="min-w-full divide-y divide-gray-300">
                <thead>
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0">
                            Naam
                        </th>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0">
                            Keuringsduur
                            <InformationCircleIcon class="inline h-5 w-5 text-gray-400 ml-1" v-tooltip="{
                                html: true,
                                content: `<span class='block w-80'>Het standaard aantal dagen waarmee de keuringsdatum van
                                een machine vooruitgeschoven wordt bij een succesvolle keuring. Dit kan overschreven
                                worden binnen het product en door een tijdelijke goedkeur.</span>`
                            }" />
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-0">
                            <span class="sr-only">Wijzig</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <tr v-for="type in internalTypes" :key="type.id" class="text-sm">
                        <td class="w-8/12 py-4 pl-4 pr-3 font-medium text-gray-900 sm:max-w-none sm:pl-0">
                            <div v-if="type.open" class="relative flex flex-grow items-stretch focus-within:z-10">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <FingerPrintIcon class="h-5 w-5 text-gray-400" />
                                </div>
                                <input type="text"
                                    class="block w-full rounded-md border-0 py-1.5 pl-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6"
                                    v-model="type.name" />
                            </div>
                            <span v-else>{{ type.name }}</span>
                        </td>
                        <td>
                            <TextInput v-if="type.open" v-model="type.typical_certificate_days" class="w-24"
                                type="number" :hasError="false" />
                            <span v-else>{{ type.typical_certificate_days }} dagen</span>
                        </td>
                        <td class="py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-0">
                            <a @click="toggleRecord(type.id)"
                                class="text-indigo-600 hover:text-indigo-900 cursor-pointer">
                                <span v-if="type.open" class="text-green-500">Opslaan</span>
                                <span v-else>Wijzig</span>
                                <TrashIcon class="ml-2 h-5 w-5 text-red-400 inline" aria-hidden="true"
                                    @click.stop="deleteType(type.id)" />
                                <span class="sr-only">, {{ type.name }}</span>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationComponent v-if="internalTypes.length" :paginator="productTypes" :params="{ search: searchTerm }"
            class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen producttypen gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw producttype toe.</p>
        </div>
    </BoxComponent>
</template>

<script setup>
import { FingerPrintIcon, InformationCircleIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import debounce from 'lodash/debounce';
import TextInput from '@/Components/UI/TextInput.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';

const typeFormRef = ref(null)

const props = defineProps({
    productTypes: {
        type: Object,
        required: true,
    },
    search: {
        type: String,
        default: '',
    },
});

const inAction = ref(false);
const typeFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', type: 'number', default: 0 },
]

function onTypeCreated(newType) {
    if (!newType) return
    internalTypes.value.push({ id: newType.id, name: newType.name, typical_certificate_days: newType.typical_certificate_days, open: false })
    internalTypes.value.sort((a, b) => a.name.localeCompare(b.name))
}

const deleteType = (id) => {
    if (!confirm('Weet je zeker dat je dit producttype wilt verwijderen?')) {
        return;
    }
    internalTypes.value = internalTypes.value.filter(t => t.id !== id);
    useForm({}).delete(`/producttypes/${id}`, {
        preserveScroll: true,
    });
};

const internalTypes = ref(props.productTypes.data);
const searchTerm = ref(props.search);

const toggleRecord = (id) => {
    internalTypes.value = internalTypes.value.map(type => {
        if (type.open) {
            const updateForm = useForm({ name: type.name, typical_certificate_days: type.typical_certificate_days });
            updateForm.patch(`/producttypes/${type.id}`, {
                preserveScroll: true,
            });
        }
        type.open = type.id === id ? !type.open : false;
        return type;
    });
    internalTypes.value.sort((a, b) => a.name.localeCompare(b.name));
};

// Debounced search function
const searchTypes = debounce((term) => {
    inAction.value = true;
    localStorage.setItem('searchInitiated', 'true');
    router.get(`/producttypes?search=${term}`, {}, { preserveScroll: true });
}, 300);

// Watch for changes to searchTerm
watch(searchTerm, newTerm => {
    searchTypes(newTerm);
});

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false;
        localStorage.removeItem('searchInitiated');
        document.getElementById('searchInput').focus();
    }
});
</script>
