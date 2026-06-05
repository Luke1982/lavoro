<template>
    <IndexHeaderComponent title="Merken" subtitle="Hieronder een lijst van alle merken" search-url="/brands"
        search-label="Zoek binnen merken" search-placeholder="bijv. 'Verloop'" :paginator="false"
        add-label="Voeg merk toe" @add="() => brandFormRef?.show()" />
    <div v-auto-animate class="mb-4">
        <CreateRecordForm ref="brandFormRef" external-trigger action="/brands" :fields="brandFields"
            add-button-label="Voeg merk toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="innerBrands.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-12">Naam</div>
            </div>
            <div v-for="brand in innerBrands" :key="brand.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="col-span-12 flex items-center">
                    <EditableTextField :model-value="brand.name" :decoration="false"
                        @update="(val) => router.patch(`/brands/${brand.id}`, { name: val }, { preserveScroll: true })">
                        <template #display>{{ brand.name }}</template>
                    </EditableTextField>
                </div>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="brands.total" :per-page="brands.per_page" label="merken" />
                <PaginationComponent :paginator="brands" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <TagIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen merken gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import { TagIcon } from '@lucide/vue'

const brandFormRef = ref(null)

const props = defineProps({
    brands: { type: Object, required: true },
    search: { type: String, default: '' },
})

const brandFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const innerBrands = computed(() => props.brands?.data || [])
</script>
