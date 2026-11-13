<template>
    <div class="flex flex-col items-center" @dragover="over" @dragleave="active = false" @drop="drop">
        <div class="h-3 w-0.5 bg-slate-300 dark:bg-slate-600" />
        <DropdownMenu placement="bottom" width-class="w-56" title="Blok toevoegen" :button-class="[
            'flex size-6 items-center justify-center rounded-full border-2 border-dashed text-sm font-bold transition-colors',
            active ? 'border-lavoro-blue bg-lavoro-blue text-white' : 'border-slate-300 bg-white text-slate-400 hover:border-lavoro-blue hover:text-lavoro-blue dark:border-slate-600 dark:bg-slate-900',
        ].join(' ')">
            <template #button>+</template>
            <MenuItem v-for="(block, type) in BLOCKS" :key="type" v-slot="{ active: highlighted }">
                <button type="button" @click="editor.insert(lane, index, type)"
                    :class="['block w-full px-3 py-2 text-left text-sm text-gray-700 dark:text-slate-200', highlighted ? 'bg-gray-50 dark:bg-slate-700' : '']">
                    {{ block.label }}
                </button>
            </MenuItem>
        </DropdownMenu>
        <div class="h-3 w-0.5 bg-slate-300 dark:bg-slate-600" />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { MenuItem } from '@headlessui/vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import { BLOCKS } from '@/Components/Macros/macroBlocks.js'
import { useMacroEditor } from '@/Composables/useMacroEditor.js'

/**
 * The place between two blocks. Drop a block from the side bar, or a block already
 * in the macro, onto it; or click it and pick a block from the list.
 */
const props = defineProps({
    lane: { type: Array, required: true },
    index: { type: Number, required: true },
})

const editor = useMacroEditor()
const active = ref(false)

const accepts = event => ['macro/new', 'macro/move'].some(type => event.dataTransfer.types.includes(type))

function over(event) {
    if (!accepts(event)) return

    event.preventDefault()
    active.value = true
}

function drop(event) {
    active.value = false

    const type = event.dataTransfer.getData('macro/new')
    const id = event.dataTransfer.getData('macro/move')

    if (type) editor.insert(props.lane, props.index, type)
    if (id) editor.move(id, props.lane, props.index)
}
</script>
