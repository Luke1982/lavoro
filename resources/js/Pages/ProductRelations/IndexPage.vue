<template>
    <IndexHeaderComponent title="Relatietypes" subtitle="Overzicht van alle productrelatietypes"
        search-url="/productrelations" search-label="Zoek binnen relatietypes" search-placeholder="bijv. 'Onderdeel'"
        :paginator="false" add-label="Voeg relatietype toe" @add="() => formRef?.show()"
        :can-add="hasPermission('productrelation.create')" />
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="formRef" external-trigger action="/productrelations" :fields="fields"
            add-button-label="Voeg relatietype toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="innerRelations.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-11">Naam</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="relation in innerRelations" :key="relation.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="col-span-11 flex items-center">
                    <EditableTextField :model-value="relation.name" :decoration="false"
                        :readonly="!hasPermission('productrelation.update')"
                        @update="(val) => router.patch(`/productrelations/${relation.id}`, { name: val }, { preserveScroll: true, preserveState: true })">
                        <template #display>{{ relation.name }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 flex items-center justify-end">
                    <div v-if="hasPermission('productrelation.delete')"
                        class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500"
                            @click="deleteRelation(relation.id)" />
                    </div>
                </div>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="relations.total" :per-page="relations.per_page"
                    label="relatietypes" />
                <PaginationComponent :paginator="relations" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <LinkIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen relatietypes gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import { TrashIcon, LinkIcon } from '@lucide/vue'
import { hasPermission } from '@/Utilities/Utilities'

const formRef = ref(null)

const props = defineProps({
    relations: { type: Object, required: true },
    search: { type: String, default: '' },
})

const fields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const innerRelations = computed(() => props.relations?.data || [])

function deleteRelation(id) {
    if (!confirm('Weet je zeker dat je dit relatietype wilt verwijderen?')) return
    useForm({}).delete(`/productrelations/${id}`, { preserveScroll: true, preserveState: true })
}
</script>
