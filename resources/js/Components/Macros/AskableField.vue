<template>
    <div>
        <div class="mb-1 flex items-center justify-between gap-3">
            <span class="text-sm font-medium">{{ label }}</span>
            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-slate-400">
                Vragen bij uitvoeren
                <SwitchComponent :model-value="asked" @update:model-value="toggle" />
            </label>
        </div>
        <input v-if="asked" :value="model.ask" type="text" placeholder="De vraag, bijvoorbeeld &quot;Hoeveel airco's?&quot;"
            :class="INPUT" @input="model = { ask: $event.target.value }" />
        <input v-else-if="kind === 'number'" :value="model" type="number" min="1" max="999" :class="INPUT"
            @input="model = Number($event.target.value)" />
        <textarea v-else-if="kind === 'text'" :value="model" rows="2" :class="INPUT" @input="model = $event.target.value" />
        <p v-else class="text-xs text-gray-500 dark:text-slate-400">{{ fixedText }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { isAsked } from '@/Components/Macros/macroBlocks.js'

/**
 * A setting that is either filled in now or asked when the macro runs — then it
 * holds the question that will be put to whoever runs it.
 */
const props = defineProps({
    label: { type: String, required: true },
    kind: { type: String, required: true },
    /** What the setting is when it is not asked, and what it goes back to. */
    fixed: { type: [String, Number], default: null },
    fixedText: { type: String, default: '' },
})

const model = defineModel({ required: true })
const asked = computed(() => isAsked(model.value))

const INPUT = 'w-full rounded-md border-0 px-3 py-1.5 text-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-slate-900 dark:ring-slate-600'

function toggle(value) {
    model.value = value ? { ask: '' } : props.fixed
}
</script>
