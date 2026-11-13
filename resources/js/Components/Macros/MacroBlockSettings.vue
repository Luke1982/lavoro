<template>
    <div v-if="step" class="space-y-3">
        <template v-if="step.type === 'add_task'">
            <ComboBox v-model="step.service_order_task_id" :options="library.taskOptions" label="Taak"
                placeholder="Zoek een taak..." />
            <ComboBox v-model="step.product_id" :options="library.productOptions" label="Product (optioneel)"
                placeholder="Zoek een product..." />
            <AskableField v-model="step.quantity" kind="number" label="Aantal" :fixed="1" />
            <label v-if="step.product_id" class="flex items-center justify-between gap-3 text-sm">
                Checks per machine afvinken
                <SwitchComponent v-model="step.checks_per_unit" />
            </label>
            <ExtraChecksPicker :model-value="extras" :checks="library.checks" :checklists="library.checklists"
                @update:model-value="setExtras" />
        </template>

        <template v-else-if="step.type === 'question'">
            <TextInput v-model="step.question" label="Vraag" placeholder="Bijvoorbeeld: Is er een offerte?" />
            <p class="text-xs text-gray-500 dark:text-slate-400">
                Wie de macro uitvoert kiest ja of nee; daarna loopt alleen de baan van dat antwoord.
            </p>
        </template>

        <template v-else-if="step.type === 'upload_document'">
            <TextInput v-model="step.prompt" label="Wat moet er geüpload worden?" />
            <ComboBox v-model="step.document_category_id" :options="library.categoryOptions" label="Categorie (optioneel)"
                placeholder="Kies een categorie..." />
            <label class="flex items-center justify-between gap-3 text-sm">
                Alleen intern
                <SwitchComponent v-model="step.internal" />
            </label>
        </template>

        <template v-else-if="step.type === 'add_remark'">
            <AskableField v-model="step.text" kind="text" label="Opmerking" fixed="" />
            <label class="flex items-center justify-between gap-3 text-sm">
                Alleen intern
                <SwitchComponent v-model="step.internal" />
            </label>
        </template>

        <template v-else-if="step.type === 'add_service_jobs'">
            <AskableField v-model="step.location" kind="location" label="Welke machines" fixed="all"
                fixed-text="Alle actieve machines van de klant. Zet vragen aan om bij het uitvoeren een locatie te kiezen." />
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import AskableField from '@/Components/Macros/AskableField.vue'
import ExtraChecksPicker from '@/Components/ServiceOrders/Tasks/ExtraChecksPicker.vue'
import { useMacroEditor } from '@/Composables/useMacroEditor.js'

/** The settings of one block, edited in place on the block itself. */
const props = defineProps({
    stepId: { type: String, required: true },
})

const editor = useMacroEditor()
const library = editor.library
const step = computed(() => editor.find(props.stepId))

const extras = computed(() => ({
    check_ids: step.value.extra_check_ids ?? [],
    checklist_ids: step.value.extra_checklist_ids ?? [],
    titles: step.value.extra_titles ?? [],
}))

function setExtras(value) {
    Object.assign(step.value, {
        extra_check_ids: value.check_ids,
        extra_checklist_ids: value.checklist_ids,
        extra_titles: value.titles,
    })
}
</script>
