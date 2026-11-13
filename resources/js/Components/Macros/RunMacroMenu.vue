<template>
    <DropdownMenu v-if="macros.length" placement="bottom-end" width-class="w-72" title="Macro uitvoeren"
        :button-class="`${pill} text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700`">
        <template #button>
            <WorkflowIcon class="size-4" />
            <span class="hidden sm:inline">Macro</span>
        </template>
        <MenuItem v-for="macro in macros" :key="macro.id" v-slot="{ active }">
            <button type="button" @click="start(macro)" :class="['block w-full px-3 py-2 text-left text-sm', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                <span class="block font-medium text-gray-800 dark:text-slate-100">{{ macro.title }}</span>
                <span v-if="macro.description" class="block truncate text-xs text-gray-500">{{ macro.description }}</span>
            </button>
        </MenuItem>
    </DropdownMenu>

    <ModalDialog :open="!!running" :title="running ? running.title : ''" max-width-class="sm:max-w-lg"
        @update:open="value => { if (!value) running = null }">
        <div v-if="running" class="space-y-4">
            <p class="text-xs text-gray-500 dark:text-slate-400">
                {{ questions.length ? `Vraag ${position + 1} van ${questions.length}` : 'Deze macro stelt geen vragen.' }}
            </p>
            <div v-if="current">
                <p class="mb-2 text-sm font-semibold text-gray-900 dark:text-slate-100">{{ current.prompt }}</p>
                <div v-if="current.kind === 'yes_no'" class="grid grid-cols-2 gap-2">
                    <button v-for="option in [{ value: true, label: 'Ja' }, { value: false, label: 'Nee' }]" :key="option.label"
                        type="button" @click="answer(option.value); next()"
                        :class="['rounded-md border px-3 py-2 text-sm font-semibold', valueOf(current) === option.value
                            ? 'border-lavoro-blue bg-lavoro-blue text-white' : 'border-gray-300 text-gray-700 dark:border-slate-600 dark:text-slate-200']">
                        {{ option.label }}
                    </button>
                </div>
                <input v-else-if="current.kind === 'number'" type="number" min="1" max="999" :value="valueOf(current)"
                    @input="answer($event.target.value)" :class="INPUT" />
                <textarea v-else-if="current.kind === 'text'" rows="3" :value="valueOf(current)" @input="answer($event.target.value)"
                    :class="INPUT" />
                <template v-else-if="current.kind === 'file'">
                    <input :key="current.stepId" type="file" @change="answer($event.target.files[0])"
                        class="block w-full text-sm text-gray-700 dark:text-slate-200" />
                    <p v-if="valueOf(current)" class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                        Gekozen: {{ valueOf(current).name }}
                    </p>
                </template>
                <select v-else-if="current.kind === 'location'" :value="valueOf(current) ?? ''" @change="answer($event.target.value)" :class="INPUT">
                    <option value="" disabled>Kies...</option>
                    <option value="all">Alle machines van de klant</option>
                    <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.title }}</option>
                </select>
            </div>
            <p v-for="message in errorMessages" :key="message" class="text-xs text-red-600">{{ message }}</p>
        </div>
        <template #footer>
            <div class="flex justify-between gap-3">
                <button type="button" :disabled="position === 0" @click="position--"
                    class="text-sm text-gray-500 hover:text-gray-700 disabled:invisible">Vorige</button>
                <button v-if="!isLast" type="button" :disabled="!answered" @click="next"
                    class="rounded-md bg-lavoro-blue px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    Volgende
                </button>
                <button v-else type="button" :disabled="!answered || form.processing" @click="run"
                    class="rounded-md bg-green-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50">
                    Uitvoeren
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import { Workflow as WorkflowIcon } from '@lucide/vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import { usePillButton } from '@/Composables/useButtonGroup.js'

/**
 * Running a macro on the werkbon: it asks its questions one at a time, only those
 * on the lanes the answers choose, and then does everything in one go.
 */
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    /** The macros with what each step asks, as the server lays them out. */
    macros: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
})

const pill = usePillButton()

const INPUT = 'w-full rounded-md border-0 px-3 py-1.5 text-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-slate-900 dark:text-white dark:ring-slate-600'

const running = ref(null)
const position = ref(0)
const form = useForm({ answers: {} })

const valueOf = question => form.answers[question.stepId]?.[question.field]

function answer(value) {
    const { stepId, field } = current.value
    form.answers = { ...form.answers, [stepId]: { ...form.answers[stepId], [field]: value } }
}

/** The questions on the path the answers so far choose, in the order the macro meets them. */
const questions = computed(() => {
    const found = []

    const walk = steps => {
        for (const step of steps) {
            Object.entries(step.asks ?? {}).forEach(([field, ask]) => found.push({ stepId: step.id, field, ...ask }))

            if (step.type !== 'question') continue

            const chosen = form.answers[step.id]?.answer

            if (chosen === undefined) return false
            if (walk(chosen ? step.yes : step.no) === false) return false
        }

        return true
    }

    walk(running.value?.steps ?? [])

    return found
})

const current = computed(() => questions.value[position.value] ?? null)
const answered = computed(() => !current.value || ![undefined, null, ''].includes(valueOf(current.value)))
const isLast = computed(() => position.value >= questions.value.length - 1 && !(current.value?.kind === 'yes_no' && !answered.value))
const errorMessages = computed(() => [...new Set(Object.values(form.errors))])

function start(macro) {
    running.value = macro
    position.value = 0
    form.answers = {}
    form.clearErrors()
}

function next() {
    if (position.value < questions.value.length - 1) position.value++
}

function run() {
    form.post(`/serviceorders/${props.serviceOrderId}/macros/${running.value.id}/run`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { running.value = null },
    })
}
</script>
