<template>
    <div :class="['w-80 rounded-lavoro-sm border-2 shadow-sm', block.tone, selected ? 'ring-2 ring-lavoro-blue ring-offset-2 dark:ring-offset-slate-900' : '']">
        <div class="flex cursor-grab items-start gap-2 px-3 py-2 active:cursor-grabbing" draggable="true"
            @dragstart="start" @click="editor.select(step.id)">
            <GripVerticalIcon class="mt-0.5 size-4 flex-none opacity-40" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ block.label }}</p>
                <p class="truncate text-xs opacity-75">{{ block.summary(step, library) }}</p>
            </div>
            <span v-if="errors.length" class="flex-none rounded-full bg-red-600 px-1.5 text-xs font-bold text-white">!</span>
        </div>
        <div v-if="selected" class="space-y-3 border-t border-black/10 dark:border-white/10 bg-white/70 px-3 py-3 text-gray-900 dark:bg-slate-900/60 dark:text-slate-100"
            @click.stop>
            <MacroBlockSettings :step-id="step.id" />
            <p v-for="message in errors" :key="message" class="text-xs text-red-600">{{ message }}</p>
            <div class="flex justify-end">
                <button type="button" class="text-xs font-semibold text-red-600 hover:underline" @click="editor.remove(step.id)">
                    Blok verwijderen
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { GripVertical as GripVerticalIcon } from '@lucide/vue'
import MacroBlockSettings from '@/Components/Macros/MacroBlockSettings.vue'
import { BLOCKS } from '@/Components/Macros/macroBlocks.js'
import { useMacroEditor } from '@/Composables/useMacroEditor.js'

/**
 * One block of the macro. Folded it says what it does in a line; clicking it opens
 * its settings in place, clicking again folds it. Drag it by its head to move it.
 */
const props = defineProps({
    step: { type: Object, required: true },
})

const editor = useMacroEditor()
const library = editor.library
const block = computed(() => BLOCKS[props.step.type])
const selected = computed(() => editor.selectedId.value === props.step.id)
const errors = computed(() => editor.errors.value[props.step.id] ?? [])

function start(event) {
    event.dataTransfer.setData('macro/move', props.step.id)
    event.dataTransfer.effectAllowed = 'move'
}
</script>
