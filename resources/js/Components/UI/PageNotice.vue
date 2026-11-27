<template>
    <!--
        Een melding boven de inhoud van een pagina: een filter dat de assistent
        zette, een koppeling die stilligt. Eén vorm voor allemaal; de toon kiest
        de kleur van het pictogram. Lange tekst blijft op twee regels tot je
        hem openklapt.
    -->
    <div class="mb-4 flex items-start gap-3 rounded-lavoro-sm border-lavoro-box bg-white p-3 shadow-lavoro-box dark:bg-slate-900"
        :role="tone === 'warning' ? 'alert' : 'status'">
        <div :class="['flex size-9 flex-none items-center justify-center rounded-lavoro-sm', toneClasses]">
            <component :is="icon" class="size-5 stroke-2" aria-hidden="true" />
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
            <div class="min-w-0 flex-1 py-0.5">
                <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ title }}</p>
                <p v-if="text" ref="body"
                    :class="['mt-0.5 text-sm break-words text-gray-600 dark:text-slate-400', expanded ? '' : 'line-clamp-2']">
                    {{ text }}
                </p>
                <button v-if="clamped || expanded" type="button"
                    class="mt-1 text-xs font-medium text-lavoro-blue hover:opacity-80 dark:text-blue-400"
                    @click="expanded = !expanded">
                    {{ expanded ? 'Minder' : 'Alles lezen' }}
                </button>
            </div>
            <component :is="actionHref ? 'a' : 'button'" v-if="actionLabel" :href="actionHref ?? undefined"
                :type="actionHref ? undefined : 'button'"
                class="self-start whitespace-nowrap rounded-lavoro-sm border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-50 sm:self-center dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="actionHref ? undefined : $emit('action')">
                {{ actionLabel }}
            </component>
        </div>
        <button v-if="dismissible" type="button"
            class="flex size-8 flex-none items-center justify-center self-start rounded-lavoro-sm text-gray-400 hover:bg-gray-100 hover:text-gray-600 sm:self-center dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"
            @click="$emit('dismiss')">
            <span class="sr-only">Sluiten</span>
            <XMarkIcon class="size-5" aria-hidden="true" />
        </button>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    title: { type: String, required: true },
    icon: { type: [Object, Function], required: true },
    /** assistant: wat de assistent deed; warning: iets dat aandacht vraagt. */
    tone: { type: String, default: 'assistant' },
    /** De ene knop rechts: een gewone link met actionHref, anders meldt hij 'action'. */
    actionLabel: { type: String, default: null },
    actionHref: { type: String, default: null },
    dismissible: { type: Boolean, default: false },
    text: { type: String, default: '' },
})

defineEmits(['action', 'dismiss'])

const toneClasses = computed(() => ({
    assistant: 'bg-lavoro-green/40 text-slate-900 dark:bg-lavoro-green/15 dark:text-lavoro-green',
    warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
}[props.tone]))

const body = useTemplateRef('body')
const expanded = ref(false)
const clamped = ref(false)

/** Of de tekst langer is dan de twee regels die hij dicht krijgt. */
function measure() {
    clamped.value = !expanded.value && !!body.value && body.value.scrollHeight > body.value.clientHeight + 1
}

let observer = null

onMounted(() => {
    measure()
    observer = new ResizeObserver(measure)
    if (body.value) observer.observe(body.value)
})

onBeforeUnmount(() => observer?.disconnect())

/** Een andere tekst begint weer dicht, en wordt opnieuw gemeten. */
watch(() => props.text, async () => {
    expanded.value = false
    await nextTick()
    measure()
})
</script>
