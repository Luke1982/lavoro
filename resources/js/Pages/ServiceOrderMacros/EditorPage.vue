<template>
    <IndexHeaderComponent :title="macro ? 'Macro bewerken' : 'Nieuwe macro'"
        subtitle="Sleep blokken op de plussen; een vraag splitst in een ja- en een nee-baan." :show-search="false" />

    <BoxComponent class="mb-4">
        <div class="grid gap-4 md:grid-cols-[1fr_2fr_auto] md:items-end">
            <TextInput v-model="form.title" label="Naam" placeholder="Bijvoorbeeld: Airco installatie met offerte"
                :has-error="!!form.errors.title" :error-message="form.errors.title" />
            <TextInput v-model="form.description" label="Omschrijving (optioneel)"
                :has-error="!!form.errors.description" :error-message="form.errors.description" />
            <div class="flex gap-3">
                <button v-if="macro" type="button" @click="confirmDelete = true"
                    class="rounded-md border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950">
                    Verwijderen
                </button>
                <button type="button" :disabled="form.processing" @click="save"
                    class="rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    Opslaan
                </button>
            </div>
        </div>
        <p v-if="hasStepErrors" class="mt-3 text-sm text-red-600">
            Niet alle blokken zijn goed ingevuld; de blokken met een uitroepteken laten zien wat er mist.
        </p>
    </BoxComponent>

    <BoxComponent padding="p-0" extra-classes="overflow-hidden">
        <div class="flex min-h-[32rem]">
            <aside class="w-52 flex-none space-y-2 border-r border-gray-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-slate-400">Blokken</p>
                <div v-for="(block, type) in BLOCKS" :key="type" draggable="true"
                    :class="['cursor-grab rounded-lavoro-sm border-2 px-3 py-2 text-sm font-semibold active:cursor-grabbing', block.tone]"
                    @dragstart="event => event.dataTransfer.setData('macro/new', type)">
                    {{ block.label }}
                </div>
                <p class="pt-2 text-xs text-gray-500 dark:text-slate-400">
                    Sleep een blok op een plus, of klik op een plus om er een te kiezen.
                </p>
            </aside>
            <div class="flex-1 overflow-x-auto bg-[radial-gradient(#cbd5e1_1px,transparent_1px)] [background-size:16px_16px] p-6 dark:bg-[radial-gradient(#334155_1px,transparent_1px)]">
                <div class="flex min-w-max flex-col items-center">
                    <span class="rounded-full bg-slate-900 px-5 py-1.5 text-sm font-semibold text-white dark:bg-slate-100 dark:text-slate-900">Start</span>
                    <MacroLane :steps="editor.root" />
                    <span class="rounded-full bg-slate-900 px-5 py-1.5 text-sm font-semibold text-white dark:bg-slate-100 dark:text-slate-900">Klaar</span>
                </div>
            </div>
        </div>
    </BoxComponent>

    <ModalDialog :open="confirmDelete" title="Macro verwijderen?" max-width-class="sm:max-w-md"
        @update:open="value => confirmDelete = value">
        <p class="text-sm text-gray-600 dark:text-slate-300">
            "{{ macro?.title }}" verdwijnt; wat de macro op werkbonnen heeft gedaan blijft staan.
        </p>
        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="confirmDelete = false">
                    Annuleren
                </button>
                <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-700"
                    @click="router.delete(`/serviceordermacros/${macro.id}`)">
                    Verwijderen
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import MacroLane from '@/Components/Macros/MacroLane.vue'
import { BLOCKS } from '@/Components/Macros/macroBlocks.js'
import { provideMacroEditor } from '@/Composables/useMacroEditor.js'

/**
 * Building a macro: blocks dragged into a flow that runs top to bottom, where a
 * yes/no question splits it in two lanes. Saved under a name, it can be run on
 * any werkbon — and the assistant reads and runs the same steps.
 */
const props = defineProps({
    macro: { type: Object, default: null },
    tasks: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    checks: { type: Array, default: () => [] },
    checklists: { type: Array, default: () => [] },
    documentCategories: { type: Array, default: () => [] },
})

const copy = value => JSON.parse(JSON.stringify(value))

const byId = list => new Map(list.map(item => [item.id, item]))
const tasksById = computed(() => byId(props.tasks))
const productsById = computed(() => byId(props.products))
const categoriesById = computed(() => byId(props.documentCategories))

const library = computed(() => ({
    taskOptions: props.tasks.map(task => ({ id: task.id, name: task.title })),
    productOptions: props.products,
    categoryOptions: props.documentCategories,
    checks: props.checks,
    checklists: props.checklists,
    taskTitle: id => tasksById.value.get(id)?.title ?? null,
    productName: id => productsById.value.get(id)?.name ?? null,
    categoryName: id => categoriesById.value.get(id)?.name ?? null,
}))

const editor = provideMacroEditor(copy(props.macro?.definition?.steps ?? []), library)
const confirmDelete = ref(false)

const form = useForm({
    title: props.macro?.title ?? '',
    description: props.macro?.description ?? '',
    definition: { steps: [] },
})

/**
 * The save's messages, by block: "definition.steps.2.yes.0.text" belongs to the
 * block at that place in what was sent, even if a block was moved since.
 */
watch(() => form.errors, errors => {
    const found = {}

    const walk = (steps, at) => steps.forEach((step, index) => {
        const prefix = `${at}.${index}.`
        const messages = Object.entries(errors).filter(([key]) => key.startsWith(prefix)
            && !key.slice(prefix.length).match(/^(yes|no)\./)).map(([, message]) => message)

        if (messages.length) found[step.id] = messages
        if (step.type === 'question') ['yes', 'no'].forEach(branch => walk(step[branch], prefix + branch))
    })

    walk(form.definition.steps, 'definition.steps')
    editor.errors.value = found
}, { deep: true })

const hasStepErrors = computed(() => Object.keys(editor.errors.value).length > 0)

function save() {
    form.definition = { steps: copy(editor.root) }

    if (props.macro) form.patch(`/serviceordermacros/${props.macro.id}`, { preserveScroll: true })
    else form.post('/serviceordermacros')
}
</script>
