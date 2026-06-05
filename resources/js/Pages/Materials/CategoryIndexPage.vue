<template>
    <IndexHeaderComponent title="Materiaalcategorieën" subtitle="Beheer van categorieën"
        search-url="/materialcategories" :show-search="false" add-label="Voeg categorie toe"
        @add="() => categoryFormRef?.show()" />
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="categoryFormRef" external-trigger action="/materialcategories" :fields="categoryFields"
            add-button-label="Voeg categorie toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="innerCategories.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-2">Icoon</div>
                <div class="col-span-9">Naam</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="cat in innerCategories" :key="cat.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <div class="col-span-2 flex items-center">
                    <EditableTextField :decoration="false"
                        @open="iconEdits[cat.id] = cat.icon">
                        <template #display>
                            <component :is="iconComponent(cat.icon)" v-if="cat.icon" class="h-5 w-5 text-gray-600 dark:text-slate-300" />
                            <span v-else class="text-gray-400 text-xs">—</span>
                        </template>
                        <template #open="{ close }">
                            <div @click.stop>
                                <LucideIconPicker v-model="iconEdits[cat.id]"
                                    @update:modelValue="(val) => { router.patch(`/materialcategories/${cat.id}`, { name: cat.name, icon: val }, { preserveScroll: true }); close() }" />
                            </div>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-8 flex items-center">
                    <EditableTextField :model-value="cat.name" :decoration="false"
                        @update="(val) => router.patch(`/materialcategories/${cat.id}`, { name: val, icon: cat.icon }, { preserveScroll: true })">
                        <template #display>{{ cat.name }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 flex items-center justify-end">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteCategory(cat.id)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <FolderIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen categorieën gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import LucideIconPicker from '@/Components/UI/LucideIconPicker.vue'
import { FolderIcon, TrashIcon } from '@lucide/vue'
import { ICON_MAP } from '@/Utilities/lucideIconMap.js'

const categoryFormRef = ref(null)
const iconEdits = reactive({})

const props = defineProps({
    categories: { type: Array, default: () => [] },
})

const innerCategories = computed(() => props.categories)

const categoryFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'icon', label: 'Icoon', type: 'iconpicker' },
]

function iconComponent(name) {
    return name ? (ICON_MAP[name] ?? null) : null
}

function deleteCategory(id) {
    if (!confirm('Weet je zeker dat je deze categorie wilt verwijderen?')) return
    useForm({}).delete(`/materialcategories/${id}`, { preserveScroll: true })
}
</script>
