<template>
    <IndexHeaderComponent title="Gebruikseenheden" subtitle="Beheer van gebruikseenheden"
        search-url="/materialusageunits" :show-search="false" add-label="Voeg gebruikseenheid toe"
        @add="() => unitFormRef?.show()" :can-add="hasPermission('materialusageunit.create')" />
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="unitFormRef" external-trigger action="/materialusageunits" :fields="unitFields"
            add-button-label="Voeg gebruikseenheid toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="innerUnits.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-11">Naam</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="unit in innerUnits" :key="unit.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <div class="col-span-11 flex items-center">
                    <EditableTextField :model-value="unit.name" :decoration="false"
                        :readonly="!hasPermission('materialusageunit.update')"
                        @update="(val) => router.put(`/materialusageunits/${unit.id}`, { name: val }, { preserveScroll: true, preserveState: true })">
                        <template #display>{{ unit.name }}</template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 flex items-center justify-end">
                    <div v-if="hasPermission('materialusageunit.delete')"
                        class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteUnit(unit.id)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <RulerIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen gebruikseenheden gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import { RulerIcon, TrashIcon } from '@lucide/vue'
import { hasPermission } from '@/Utilities/Utilities'

const unitFormRef = ref(null)

const props = defineProps({
    usageUnits: { type: Array, default: () => [] },
})

const innerUnits = computed(() => props.usageUnits)

const unitFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

function deleteUnit(id) {
    if (!confirm('Weet je zeker dat je deze gebruikseenheid wilt verwijderen?')) return
    useForm({}).delete(`/materialusageunits/${id}`, { preserveScroll: true, preserveState: true })
}
</script>
