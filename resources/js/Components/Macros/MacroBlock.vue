<template>
    <div ref="root" :data-block="step.type" :class="['w-full scroll-mb-28 rounded-lavoro-sm border-2 shadow-sm md:w-80 lg:scroll-mb-0', block.tone, selected ? 'ring-2 ring-lavoro-blue ring-offset-2 dark:ring-offset-slate-900' : '']">
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
            <p v-for="message in errors" :key="message" class="text-xs text-red-600 dark:text-red-400">{{ message }}</p>
            <div class="flex items-center justify-between" data-tour="macro.block.controls">
                <div class="flex gap-1">
                    <button type="button" title="Omhoog" :disabled="isFirst" :class="MOVE_BUTTON"
                        @click="editor.shift(step.id, -1)">
                        <ArrowUpIcon class="size-4" />
                    </button>
                    <button type="button" title="Omlaag" :disabled="isLast" :class="MOVE_BUTTON"
                        @click="editor.shift(step.id, 1)">
                        <ArrowDownIcon class="size-4" />
                    </button>
                </div>
                <button type="button" class="text-xs font-semibold text-red-600 dark:text-red-400 hover:underline" @click="editor.remove(step.id)">
                    Blok verwijderen
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { ArrowDown as ArrowDownIcon, ArrowUp as ArrowUpIcon, GripVertical as GripVerticalIcon } from '@lucide/vue'
import MacroBlockSettings from '@/Components/Macros/MacroBlockSettings.vue'
import { BLOCKS } from '@/Components/Macros/macroBlocks.js'
import { useMacroEditor } from '@/Composables/useMacroEditor.js'

/**
 * One block of the macro. Folded it says what it does in a line; clicking it opens
 * its settings in place, clicking again folds it. Drag it by its head to move it,
 * or, where dragging does not work, as on a phone, with the arrows in its settings.
 */
const props = defineProps({
    step: { type: Object, required: true },
    isFirst: { type: Boolean, default: false },
    isLast: { type: Boolean, default: false },
})

const MOVE_BUTTON = 'rounded-md border border-black/10 p-2 hover:bg-black/5 disabled:opacity-30 dark:border-white/10 dark:hover:bg-white/10'

const editor = useMacroEditor()
const library = editor.library
const block = computed(() => BLOCKS[props.step.type])
const selected = computed(() => editor.selectedId.value === props.step.id)
const errors = computed(() => editor.errors.value[props.step.id] ?? [])
const root = ref(null)

/**
 * A block added at the far end of a long macro would otherwise open out of sight,
 * or under the menu bar at the bottom of a phone.
 */
onMounted(() => {
    if (selected.value) root.value.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
})

function start(event) {
    event.dataTransfer.setData('macro/move', props.step.id)
    event.dataTransfer.effectAllowed = 'move'
}
</script>
