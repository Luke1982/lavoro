<template>
    <ModalDialog :open="!!state.question" @update:open="onOpenChange" :title="question?.title ?? ''">
        <div v-if="question" class="flex flex-col gap-5">
            <p v-if="question.subtitle" class="-mt-2 text-sm text-gray-600 dark:text-slate-300">{{ question.subtitle }}</p>

            <ul v-if="question.items?.length"
                class="divide-y divide-gray-100 rounded-xl border border-gray-100 bg-gray-50 px-4 dark:divide-slate-700 dark:border-slate-700 dark:bg-slate-900">
                <li v-for="(item, index) in question.items" :key="index"
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2.5 text-sm">
                    <span class="font-semibold text-gray-900 dark:text-slate-100">{{ item.title }}</span>
                    <span v-if="item.subtitle" class="text-gray-500 dark:text-slate-400">{{ item.subtitle }}</span>
                </li>
            </ul>

            <fieldset class="flex flex-col gap-2.5">
                <legend v-if="question.legend" class="mb-2.5 text-sm font-bold text-gray-900 dark:text-slate-100">
                    {{ question.legend }}
                </legend>
                <label v-for="choice in question.choices" :key="choice.value"
                    :class="[chosen === choice.value
                        ? 'border-lavoro-blue bg-lavoro-lightblue/60 shadow-sm dark:bg-blue-950/40'
                        : 'border-gray-200 bg-white hover:border-gray-300 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-slate-600',
                        'flex cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 transition duration-200 active:scale-[0.99] motion-reduce:transition-none']">
                    <input type="radio" name="tray-paste-choice" :value="choice.value" v-model="chosen"
                        class="mt-0.5 size-4 accent-lavoro-blue" />
                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ choice.label }}</span>
                        <span class="text-sm text-gray-600 dark:text-slate-300">{{ choice.description }}</span>
                    </span>
                </label>
            </fieldset>

            <label class="flex min-h-11 items-center gap-2.5 text-sm text-gray-700 dark:text-slate-200">
                <input type="checkbox" v-model="remove" class="size-4 accent-lavoro-blue" />
                Daarna uit het bakje halen
            </label>
        </div>

        <template #footer>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" @click="dismissTrayQuestion"
                    class="h-11 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 active:scale-95 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                    Annuleren
                </button>
                <button type="button" :disabled="!chosen || state.busy" @click="answerTrayQuestion(chosen, remove)"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-lavoro-blue px-4 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50">
                    <LoaderCircleIcon v-if="state.busy" class="size-4 animate-spin" aria-hidden="true" />
                    <Transition mode="out-in" enter-active-class="transition duration-200 motion-reduce:transition-none"
                        enter-from-class="translate-y-1 opacity-0" leave-active-class="transition duration-100 motion-reduce:transition-none"
                        leave-to-class="-translate-y-1 opacity-0">
                        <span :key="confirmLabel">{{ confirmLabel }}</span>
                    </Transition>
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { LoaderCircleIcon } from '@lucide/vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import { useTray, answerTrayQuestion, dismissTrayQuestion } from '@/Composables/useTray.js'

const { state } = useTray()

/**
 * The last question asked, kept while the dialog slides away: emptied at once,
 * the dialog would collapse to nothing halfway through its own exit.
 */
const question = ref(null)
const chosen = ref(null)
const remove = ref(true)

watch(() => state.question, (value) => {
    if (!value) return
    question.value = value
    chosen.value = value.choices[0]?.value ?? null
    remove.value = true
})

const confirmLabel = computed(() =>
    question.value?.choices.find(choice => choice.value === chosen.value)?.confirm ?? 'Plakken')

const onOpenChange = (open) => {
    if (!open) dismissTrayQuestion()
}
</script>
