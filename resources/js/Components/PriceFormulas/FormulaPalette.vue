<template>
    <aside class="shrink-0 overflow-y-auto rounded-lg bg-white p-3 shadow-sm lg:w-44 dark:bg-slate-800" data-tour="formula.palette">
        <p class="mb-2 text-xs text-gray-500 dark:text-slate-400">Sleep een blok op het canvas, of klik erop.</p>
        <div v-for="(label, category) in catalog.categories" :key="category" class="mb-3" :data-palette="category">
            <p class="mb-1 text-xs font-bold uppercase tracking-wide" :style="{ color: CATEGORY_COLORS[category] }">{{ label }}</p>
            <button v-for="block in blocksIn(category)" :key="block.type" type="button" draggable="true"
                class="mb-1 block w-full cursor-grab rounded-md border border-gray-200 px-2 py-1.5 text-left text-xs hover:border-lavoro-blue dark:border-slate-600"
                :title="block.description" :data-palette-block="block.type"
                @dragstart="event => onDragStart(event, block.type)" @click="$emit('add', block.type)">
                <span class="font-medium text-gray-800 dark:text-slate-100">{{ block.label }}</span>
            </button>
        </div>
    </aside>
</template>

<script setup>
import { CATEGORY_COLORS, DRAG_TYPE } from './formulaEditor'

/** Every block there is, by group: dragged onto the canvas, or clicked to land in the middle of it. */
const props = defineProps({
    catalog: { type: Object, required: true },
})

defineEmits(['add'])

const blocksIn = category => props.catalog.types.filter(type => type.category === category)

function onDragStart(event, type) {
    event.dataTransfer.setData(DRAG_TYPE, type)
    event.dataTransfer.effectAllowed = 'move'
}
</script>
