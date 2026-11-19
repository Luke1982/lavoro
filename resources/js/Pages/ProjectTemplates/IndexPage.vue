<template>
    <IndexHeaderComponent title="Projectsjablonen"
        subtitle="De mijlpalen die een soort project altijd doorloopt, zodat een nieuw project ermee begint."
        :show-search="false" add-label="Sjabloon toevoegen" @add="edit(null)" />

    <div v-if="templates.length" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <BoxComponent v-for="template in templates" :key="template.id">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ template.name }}</h2>
                    <p v-if="template.description" class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                        {{ template.description }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" class="cursor-pointer text-gray-400 hover:text-gray-700 dark:text-slate-500 dark:hover:text-slate-200"
                        aria-label="Sjabloon bewerken" @click="edit(template)">
                        <PencilSquareIcon class="size-4" />
                    </button>
                    <button type="button" class="cursor-pointer text-red-400 hover:text-red-600 dark:text-red-300 dark:hover:text-red-400"
                        aria-label="Sjabloon verwijderen" @click="remove(template)">
                        <TrashIcon class="size-4" />
                    </button>
                </div>
            </div>
            <ol class="mt-3 space-y-1.5 border-l-2 border-gray-200 pl-3 dark:border-slate-700">
                <li v-for="step in template.milestones" :key="step.id" class="text-sm">
                    <span class="text-gray-800 dark:text-slate-200">{{ step.title }}</span>
                    <span class="ml-1.5 text-xs text-gray-500 dark:text-slate-400">{{ dayLabel(step.offset_days) }}</span>
                </li>
            </ol>
        </BoxComponent>
    </div>
    <BoxComponent v-else>
        <p class="py-4 text-center text-sm text-gray-500 dark:text-slate-400">
            Nog geen sjablonen. Maak er een voor elk soort project dat steeds dezelfde stappen doorloopt.
        </p>
    </BoxComponent>

    <DrawerComponent v-model="drawerOpen" max-width-class="max-w-2xl"
        :title="editing ? 'Sjabloon bewerken' : 'Nieuw sjabloon'"
        subtitle="Elke mijlpaal valt een aantal dagen na de startdatum van het project.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <DrawerField label="Naam">
                <TextInput v-model="form.name" type="text" placeholder="Bijvoorbeeld VRF-installatie"
                    :hasError="Boolean(form.errors.name)" :errorMessage="form.errors.name" />
            </DrawerField>
            <DrawerField label="Omschrijving">
                <TextInput v-model="form.description" type="text" placeholder="Optioneel"
                    :hasError="Boolean(form.errors.description)" :errorMessage="form.errors.description" />
            </DrawerField>
            <div class="px-4 py-4 sm:px-6">
                <div class="mb-2 grid grid-cols-12 gap-2 text-xs font-bold text-gray-600 dark:text-slate-400">
                    <span class="col-span-7">Mijlpaal</span>
                    <span class="col-span-4">Dagen na de start</span>
                </div>
                <div v-auto-animate class="space-y-2">
                    <div v-for="(step, index) in form.milestones" :key="step.key" class="grid grid-cols-12 items-start gap-2">
                        <div class="col-span-7">
                            <TextInput v-model="step.title" type="text" placeholder="Naam van de stap"
                                :hasError="Boolean(form.errors[`milestones.${index}.title`])"
                                :errorMessage="form.errors[`milestones.${index}.title`]" />
                        </div>
                        <div class="col-span-4">
                            <TextInput v-model="step.offset_days" type="number"
                                :hasError="Boolean(form.errors[`milestones.${index}.offset_days`])"
                                :errorMessage="form.errors[`milestones.${index}.offset_days`]" />
                        </div>
                        <button type="button" class="col-span-1 mt-2 cursor-pointer justify-self-center text-red-400 hover:text-red-600 dark:text-red-300"
                            :aria-label="`Stap ${index + 1} weghalen`" @click="form.milestones.splice(index, 1)">
                            <TrashIcon class="size-4" />
                        </button>
                    </div>
                </div>
                <p v-if="form.errors.milestones" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ form.errors.milestones }}</p>
                <button type="button" @click="addStep"
                    class="mt-3 inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-lavoro-blue dark:text-blue-400 hover:underline">
                    <PlusIcon class="size-3.5" />
                    Mijlpaal toevoegen
                </button>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="drawerOpen = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="save" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import DrawerField from '@/Components/UI/DrawerField.vue'
import TextInput from '@/Components/UI/TextInput.vue'

defineProps({
    templates: { type: Array, default: () => [] },
})

const drawerOpen = ref(false)
const editing = ref(null)
let nextKey = 0

const form = useForm({ name: '', description: '', milestones: [] })

function step(title = '', offsetDays = 0, description = null) {
    return { key: nextKey++, title, offset_days: offsetDays, description }
}

function addStep() {
    const last = form.milestones.at(-1)
    form.milestones.push(step('', last ? Number(last.offset_days) + 7 : 0))
}

function edit(template) {
    editing.value = template
    form.clearErrors()
    form.name = template?.name ?? ''
    form.description = template?.description ?? ''
    form.milestones = template
        ? template.milestones.map(item => step(item.title, item.offset_days, item.description))
        : [step('', 0)]
    drawerOpen.value = true
}

function dayLabel(days) {
    if (days === 0) return 'op de startdag'
    const count = Math.abs(days) === 1 ? '1 dag' : `${Math.abs(days)} dagen`
    return days > 0 ? `${count} na de start` : `${count} vóór de start`
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { drawerOpen.value = false } }
    const payload = form.transform(data => ({
        ...data,
        milestones: data.milestones.map(({ title, description, offset_days }) => ({ title, description, offset_days })),
    }))

    if (editing.value) {
        payload.put(`/projecttemplates/${editing.value.id}`, options)
    } else {
        payload.post('/projecttemplates', options)
    }
}

function remove(template) {
    if (!confirm(`Sjabloon '${template.name}' verwijderen? Projecten die ermee gemaakt zijn houden hun mijlpalen.`)) return
    router.delete(`/projecttemplates/${template.id}`, { preserveScroll: true })
}
</script>
