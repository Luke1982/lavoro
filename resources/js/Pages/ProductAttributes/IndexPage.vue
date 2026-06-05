<template>
    <IndexHeaderComponent title="Productkenmerken"
        subtitle="Overzicht van alle kenmerken die aan producten kunnen worden gekoppeld"
        search-url="/productattributes" search-label="Zoek binnen kenmerken" search-placeholder="bijv. 'Kleur'"
        :paginator="false" add-label="Voeg kenmerk toe" @add="() => formRef?.show()"
        :can-add="hasPermission('productattribute.create')" />
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="formRef" external-trigger action="/productattributes" :fields="fields"
            add-button-label="Voeg kenmerk toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="innerAttributes.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-11">Naam</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="attr in innerAttributes" :key="attr.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="col-span-11 flex items-center">
                    <EditableTextField :model-value="attr.name" :decoration="false"
                        :readonly="!hasPermission('productattribute.update')"
                        @update="(val) => router.patch(`/productattributes/${attr.id}`, { name: val }, { preserveScroll: true })">
                        <template #display>{{ attr.name }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 flex items-center justify-end gap-2">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <Link :href="`/productattributes/${attr.id}`" class="text-sm text-lavoro-darkerblue">
                            <EyeIcon class="h-5 w-5" />
                        </Link>
                    </div>
                    <div v-if="hasPermission('productattribute.delete')"
                        class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500"
                            @click="deleteAttribute(attr.id)" />
                    </div>
                </div>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="attributes.total" :per-page="attributes.per_page"
                    label="kenmerken" />
                <PaginationComponent :paginator="attributes" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <TagsIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen kenmerken gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import { EyeIcon, TrashIcon, TagsIcon } from '@lucide/vue'
import { hasPermission } from '@/Utilities/Utilities'

const formRef = ref(null)

const props = defineProps({
    attributes: { type: Object, required: true },
    search: { type: String, default: '' },
})

const fields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const innerAttributes = computed(() => props.attributes?.data || [])

function deleteAttribute(id) {
    if (!confirm('Weet je zeker dat je dit kenmerk wilt verwijderen?')) return
    useForm({}).delete(`/productattributes/${id}`, { preserveScroll: true })
}
</script>
