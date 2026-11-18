<template>
    <div class="flex items-center mb-6">
        <Link :href="kind.path" class="text-slate-400 text-sm font-medium capitalize">{{ kind.plural }}</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-200 font-bold text-sm">Importeren</span>
    </div>

    <div class="mb-6">
        <h1 class="text-2xl font-bold first-letter:uppercase">{{ kind.plural }} importeren</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
            {{ catalogImport.original_name }}<span v-if="catalogImport.supplier_name"> · {{ catalogImport.supplier_name }}</span>
        </p>
    </div>

    <StepsProgressBar class="mb-8" :steps="steps" :model-value="step" @update:model-value="goTo" />

    <ImportMappingStep v-if="step === 'map'" :import-path="catalogImport.path" :kind="kind" :settings="settings"
        :sheet="sheet" :fields="fields" :match-keys="matchKeys" :suppliers="suppliers" :mappings="mappings"
        :suggested-mapping-id="suggestedMappingId" @mapped="editing = false" @cancel="cancel" />
    <ImportPreviewStep v-else-if="step === 'check'" :import-path="catalogImport.path" :kind="kind" :preview="preview"
        @back="editing = true" />
    <ImportProgressStep v-else :catalog-import="catalogImport" :kind="kind" />
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ChevronRightIcon } from '@heroicons/vue/20/solid'
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
import ImportMappingStep from '@/Components/Imports/ImportMappingStep.vue'
import ImportPreviewStep from '@/Components/Imports/ImportPreviewStep.vue'
import ImportProgressStep from '@/Components/Imports/ImportProgressStep.vue'

const props = defineProps({
    catalogImport: { type: Object, required: true },
    /** What is imported: { value, noun, plural, path }. */
    kind: { type: Object, required: true },
    settings: { type: Object, default: null },
    sheet: { type: Object, default: null },
    fields: { type: Array, default: () => [] },
    matchKeys: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    mappings: { type: Array, default: () => [] },
    suggestedMappingId: { type: Number, default: null },
    preview: { type: Object, default: null },
})

const steps = [
    { id: 'map', name: 'Kolommen koppelen' },
    { id: 'check', name: 'Controleren' },
    { id: 'run', name: 'Importeren' },
]

const editing = ref(false)

const step = computed(() => {
    if (!['uploaded', 'mapped'].includes(props.catalogImport.status)) return 'run'
    if (props.catalogImport.status === 'mapped' && props.preview && !editing.value) return 'check'

    return 'map'
})

function goTo(id) {
    if (step.value === 'run') return
    if (id === 'map') editing.value = true
    if (id === 'check' && props.catalogImport.status === 'mapped') editing.value = false
}

function cancel() {
    router.delete(props.catalogImport.path)
}
</script>
