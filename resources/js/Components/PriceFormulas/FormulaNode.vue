<template>
    <div class="w-64 rounded-lg border bg-white text-xs shadow-sm dark:bg-slate-800"
        :class="[selected ? 'border-lavoro-blue ring-2 ring-lavoro-blue/40' : 'border-gray-200 dark:border-slate-600',
            error ? 'border-red-400' : '']">
        <div class="flex items-center justify-between gap-2 rounded-t-lg px-3 py-1.5 font-semibold text-white"
            :style="{ backgroundColor: color }">
            <span class="truncate">{{ data?.title || definition.label }}</span>
            <span v-if="data?.title" class="truncate text-[10px] font-normal opacity-80">{{ definition.label }}</span>
        </div>

        <p v-if="summary" class="truncate px-3 pt-2 text-gray-600 dark:text-slate-300" :title="summary">{{ summary }}</p>

        <div class="py-1.5">
            <div v-for="input in definition.inputs" :key="'in-' + input.id"
                class="relative flex items-center justify-between gap-2 px-3 py-1">
                <Handle type="target" :id="input.id" :position="Position.Left" :class="handleClass(input.kind)" />
                <span class="text-gray-700 dark:text-slate-200">{{ input.label }}</span>
                <span v-if="result?.inputs?.[input.id] != null" class="tabular-nums text-gray-400 dark:text-slate-500">
                    {{ formatNumber(result.inputs[input.id]) }}
                </span>
            </div>
            <div v-for="output in definition.outputs" :key="'out-' + output.id"
                class="relative flex items-center justify-end gap-2 px-3 py-1">
                <button v-if="output.kind === 'machines' && result?.outputs?.[output.id]?.count" type="button"
                    class="nodrag tabular-nums font-medium text-blue-600 underline decoration-dotted underline-offset-2 hover:text-blue-800 dark:text-blue-400"
                    title="Welke machines" data-machine-toggle @click.stop="toggleList(output.id)">
                    {{ shown(output) }}
                </button>
                <span v-else-if="shown(output)" class="tabular-nums font-medium text-gray-900 dark:text-white">
                    {{ shown(output) }}
                </span>
                <div v-if="openList === output.id" ref="listRef"
                    class="nodrag nowheel absolute right-2 top-full z-10 mt-1 max-h-56 w-64 overflow-y-auto rounded-md bg-white p-2 text-left text-xs shadow-lg ring-1 ring-black/10 dark:bg-slate-900 dark:ring-slate-600">
                    <p class="mb-1 font-semibold text-gray-900 dark:text-white">{{ output.label }}: {{ shown(output) }}</p>
                    <ul class="space-y-0.5 text-gray-700 dark:text-slate-300">
                        <li v-for="line in machineLines(output)" :key="line">{{ line }}</li>
                    </ul>
                    <p v-if="hiddenCount(output)" class="mt-1 text-gray-400">en {{ hiddenCount(output) }} meer</p>
                </div>
                <span class="text-gray-700 dark:text-slate-200">{{ output.label }}</span>
                <Handle type="source" :id="output.id" :position="Position.Right" :class="handleClass(output.kind)" />
            </div>
        </div>

        <p v-if="isResult" class="px-3 pb-2 text-base font-bold tabular-nums text-gray-900 dark:text-white">
            {{ resultValue === null ? '—' : formatResult(resultValue) }}
        </p>
        <p v-if="error" class="px-3 pb-2 text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { CATEGORY_COLORS, EDITOR_KEY, formatNumber, formatResult, summarize } from './formulaEditor'

/**
 * One block on the canvas: its inputs on the left, its outputs on the right,
 * each with what it comes to in the current trial run, as the server worked it out.
 */
const props = defineProps({
    id: { type: String, required: true },
    type: { type: String, required: true },
    data: { type: Object, default: () => ({}) },
    selected: { type: Boolean, default: false },
})

const editor = inject(EDITOR_KEY)

const definition = computed(() => editor.definitions[props.type])
const color = computed(() => CATEGORY_COLORS[definition.value.category] ?? '#475569')
const summary = computed(() => summarize({ type: props.type, data: props.data }, definition.value, editor.names.value))
const result = computed(() => editor.results.value?.nodes?.[props.id] ?? null)
const error = computed(() => result.value?.error ?? null)
const isResult = computed(() => definition.value.category === 'result')
const resultValue = computed(() => result.value?.outputs?.value?.value ?? null)

function shown(output) {
    const value = result.value?.outputs?.[output.id]
    if (!value) return ''
    if (value.kind === 'machines') return value.count === 1 ? '1 machine' : `${value.count} machines`

    return formatNumber(value.value)
}

/** Which output's machines are listed; clicking the count again, or anywhere else, closes it. */
const openList = ref(null)
const listRef = ref(null)

function toggleList(id) {
    openList.value = openList.value === id ? null : id
}

function closeOutside(event) {
    const list = Array.isArray(listRef.value) ? listRef.value[0] : listRef.value
    if (event.target.closest?.('[data-machine-toggle]')) return
    if (list && !list.contains(event.target)) openList.value = null
}

watch(openList, id => {
    document.removeEventListener('pointerdown', closeOutside, true)
    if (id) setTimeout(() => document.addEventListener('pointerdown', closeOutside, true))
})

onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOutside, true))

/** The machines on an output, the same ones counted once with how many: "Daikin FTXM25 ×3". */
function machineLines(output) {
    const counts = new Map()
    for (const label of result.value?.outputs?.[output.id]?.labels ?? []) counts.set(label, (counts.get(label) ?? 0) + 1)

    return [...counts].map(([label, count]) => (count > 1 ? `${label} ×${count}` : label))
}

function hiddenCount(output) {
    const value = result.value?.outputs?.[output.id]

    return value ? Math.max(0, value.count - value.labels.length) : 0
}

function handleClass(kind) {
    return kind === 'machines' ? 'formula-handle-machines' : 'formula-handle-number'
}
</script>

<style>
.vue-flow__handle.formula-handle-machines {
    width: 12px;
    height: 12px;
    background: #2563eb;
    border: 2px solid #fff;
}

.vue-flow__handle.formula-handle-number {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    background: #64748b;
    border: 2px solid #fff;
}
</style>
