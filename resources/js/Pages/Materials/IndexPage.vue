<template>
    <IndexHeaderComponent title="Materialen" subtitle="Zoek binnen materialen" search-url="/materials"
        search-label="Zoek binnen materialen" search-placeholder="Zoek op naam, code of categorie"
        add-label="Voeg materiaal toe" :paginator="false" @add="() => materialFormRef?.show()">
        <template #filters>
            <div>
                <button @click="importMaterials" :disabled="importingMaterials"
                    class="ml-auto px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700 disabled:bg-gray-400">
                    SnelStart materialen importeren
                </button>
            </div>
        </template>
    </IndexHeaderComponent>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="materialFormRef" external-trigger action="/materials" :fields="materialFields"
            add-button-label="Voeg materiaal toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="innerMaterials.length">
            <div
                class="hidden lg:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Naam</div>
                <div class="col-span-2">Code</div>
                <div class="col-span-2">Prijs</div>
                <div class="col-span-1">Deelbaar</div>
                <div class="col-span-1">Actief</div>
                <div class="col-span-2">Categorie</div>
                <div class="col-span-1">Eenheid</div>
            </div>
            <div v-for="mat in innerMaterials" :key="mat.id" role="row"
                class="flex items-center text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="grid grid-cols-12 flex-1 p-4 items-center">
                <div class="col-span-10 lg:col-span-3 flex items-center">
                    <EditableTextField :model-value="mat.name" :decoration="false"
                        @update="(val) => updateMaterial(mat, { name: val })">
                        <template #display>
                            <span class="font-semibold">{{ mat.name }}</span>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <EditableTextField :model-value="mat.code" :decoration="false" placeholder="—"
                        @update="(val) => updateMaterial(mat, { code: val })">
                        <template #display>{{ mat.code || '—' }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <EditableTextField :model-value="mat.price" inputType="currency" :decoration="false"
                        @update="(val) => updateMaterial(mat, { price: val })">
                        <template #display>{{ formatPrice(mat.price) }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 items-center hidden lg:flex pr-2">
                    <EditableTextField :decoration="false"
                        @open="boolEdits[mat.id] = { divisable: mat.divisable }">
                        <template #display>
                            <BadgeComponent :color="mat.divisable ? 'green' : 'gray'" :has-dot="false">
                                {{ mat.divisable ? 'Ja' : 'Nee' }}
                            </BadgeComponent>
                        </template>
                        <template #open="{ close }">
                            <div class="flex gap-2" @click.stop>
                                <SwitchComponent v-model="boolEdits[mat.id].divisable"
                                    @update:modelValue="updateMaterial(mat, { divisable: boolEdits[mat.id].divisable }, close)" />
                            </div>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 items-center hidden lg:flex pr-2">
                    <EditableTextField :decoration="false"
                        @open="boolEdits[mat.id] = { ...boolEdits[mat.id], is_active: mat.is_active }">
                        <template #display>
                            <BadgeComponent :color="mat.is_active ? 'green' : 'gray'" :has-dot="false">
                                {{ mat.is_active ? 'Actief' : 'Inactief' }}
                            </BadgeComponent>
                        </template>
                        <template #open="{ close }">
                            <div class="flex gap-2" @click.stop>
                                <SwitchComponent v-model="boolEdits[mat.id].is_active"
                                    @update:modelValue="updateMaterial(mat, { is_active: boolEdits[mat.id].is_active }, close)" />
                            </div>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <EditableTextField type="combobox" :model-value="mat.material_category_id" :options="categories"
                        :decoration="false"
                        @update="(val) => updateMaterial(mat, { material_category_id: val })">
                        <template #display>{{ mat.category?.name || '—' }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 items-center hidden lg:flex pr-2">
                    <EditableTextField type="combobox" :model-value="mat.material_usage_unit_id" :options="usageUnits"
                        :decoration="false"
                        @update="(val) => updateMaterial(mat, { material_usage_unit_id: val })">
                        <template #display>{{ mat.usageUnit?.name || '—' }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 lg:hidden flex items-center justify-end text-xs text-gray-500 gap-2">
                    <BadgeComponent :color="mat.is_active ? 'green' : 'gray'" :has-dot="false">
                        {{ mat.is_active ? 'Actief' : 'Inactief' }}
                    </BadgeComponent>
                </div>
                </div>
                <Link :href="`/materials/${mat.id}`"
                    class="mx-2 shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium text-lavoro-blue border border-lavoro-blue/30 hover:bg-lavoro-blue hover:text-white transition-colors">
                    <ChevronRightIcon class="h-3.5 w-3.5" />
                    <span class="hidden sm:inline">Details</span>
                </Link>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="materials.total" :per-page="materials.per_page" label="materialen" />
                <PaginationComponent :paginator="materials" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <PackageIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen materialen gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { router, useForm, Link } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { PackageIcon } from '@lucide/vue'
import { ChevronRightIcon } from '@heroicons/vue/24/outline'

const materialFormRef = ref(null)
const importingMaterials = ref(false)
const importMaterialsForm = useForm({})
const boolEdits = reactive({})

const { materials, categories, usageUnits } = defineProps({
    materials: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    usageUnits: { type: Array, default: () => [] },
    search: { type: String, default: '' },
})

const innerMaterials = computed(() => materials?.data || [])

const materialFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'code', label: 'Code', type: 'text' },
    { key: 'price', label: 'Prijs', type: 'currency', default: 0 },
    { key: 'material_category_id', label: 'Categorie', type: 'combobox', options: categories, initialId: categories[0]?.id },
    { key: 'material_usage_unit_id', label: 'Gebruikseenheid', type: 'combobox', options: usageUnits, initialId: usageUnits[0]?.id },
    { key: 'divisable', label: 'Deelbaar', type: 'boolean', class: 'w-auto' },
    { key: 'is_active', label: 'Actief', type: 'boolean', class: 'w-auto', default: true },
]

const currencyFormatter = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' })

function formatPrice(price) {
    if (price === null || price === undefined) return '—'
    return currencyFormatter.format(Number(price))
}

function updateMaterial(mat, patch, close = null) {
    router.patch(`/materials/${mat.id}`, {
        name: mat.name,
        stock: mat.stock ?? 0,
        min_stock: mat.min_stock ?? 0,
        max_stock: mat.max_stock ?? 0,
        material_category_id: mat.material_category_id,
        material_usage_unit_id: mat.material_usage_unit_id,
        price: mat.price,
        code: mat.code,
        divisable: mat.divisable,
        is_active: mat.is_active,
        is_service: mat.is_service,
        ...patch,
    }, {
        preserveScroll: true,
        onSuccess: () => close?.(),
    })
}

function importMaterials() {
    importingMaterials.value = true
    importMaterialsForm.post('/imports/snelstart/materials', {
        preserveScroll: true,
        onFinish: () => { importingMaterials.value = false },
    })
}
</script>
