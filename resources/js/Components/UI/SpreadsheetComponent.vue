<template>
    <div ref="container" class="spreadsheet-container overflow-x-auto"></div>
</template>

<script setup>
import { ref, toRaw, onMounted, onBeforeUnmount, watch } from 'vue'
import jspreadsheet from 'jspreadsheet-ce'
import 'jspreadsheet-ce/dist/jspreadsheet.css'
import 'jsuites/dist/jsuites.css'
import 'material-icons/iconfont/filled.css'
import { spreadsheetDictionaryNl, translateToolbarNl } from './spreadsheetLocaleNl'

jspreadsheet.setDictionary(spreadsheetDictionaryNl())

/**
 * jspreadsheet applies this to every cell that has no explicit style of its
 * own, so storing it back would bloat the payload without carrying meaning.
 */
const DEFAULT_CELL_STYLE = 'text-align: center;'

const props = defineProps({
    modelValue: { type: [Array, Object], default: null },
    columns: { type: Array, default: () => [] },
    minDimensions: { type: Array, default: () => [6, 15] },
    readonly: { type: Boolean, default: false },
    toolbar: { type: Boolean, default: true },
    debounce: { type: Number, default: 600 },
})

const emit = defineEmits(['update:modelValue', 'change'])

const container = ref(null)
let worksheet = null
let debounceTimer = null
let suppressEmit = false

/**
 * Accepts either the current snapshot shape or the bare 2D array that earlier
 * versions of this component stored.
 */
function normalizeSnapshot(value) {
    if (Array.isArray(value)) {
        return { data: value, style: {}, mergeCells: {}, columns: [] }
    }

    return {
        data: value?.data ?? [],
        style: value?.style ?? {},
        mergeCells: value?.mergeCells ?? {},
        columns: value?.columns ?? [],
    }
}

function currentSnapshot() {
    if (!worksheet) return normalizeSnapshot(null)

    const config = worksheet.getConfig()

    const style = {}
    Object.entries(config.style ?? {}).forEach(([cell, css]) => {
        if (css && css.trim() !== DEFAULT_CELL_STYLE) {
            style[cell] = css
        }
    })

    const mergeCells = {}
    Object.entries(config.mergeCells ?? {}).forEach(([cell, spec]) => {
        mergeCells[cell] = [spec[0], spec[1]]
    })

    const columns = (config.columns ?? []).map((column) => {
        const kept = {}
        if (column.width !== undefined) kept.width = column.width
        if (column.title !== undefined) kept.title = column.title
        return kept
    })

    return {
        data: config.data ?? [],
        style,
        mergeCells,
        columns: columns.some((c) => Object.keys(c).length) ? columns : [],
    }
}

function sameAsModel(snapshot) {
    return JSON.stringify(snapshot) === JSON.stringify(normalizeSnapshot(toRaw(props.modelValue)))
}

function flush() {
    clearTimeout(debounceTimer)
    debounceTimer = null

    const snapshot = currentSnapshot()
    if (sameAsModel(snapshot)) return

    emit('update:modelValue', snapshot)
    emit('change', snapshot)
}

function emitChange() {
    if (suppressEmit) return

    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(flush, props.debounce)
}

function buildOptions() {
    const snapshot = normalizeSnapshot(toRaw(props.modelValue))

    return {
        toolbar: props.toolbar ? translateToolbarNl : false,
        onchange: emitChange,
        onpaste: emitChange,
        oninsertrow: emitChange,
        ondeleterow: emitChange,
        oninsertcolumn: emitChange,
        ondeletecolumn: emitChange,
        onmoverow: emitChange,
        onmovecolumn: emitChange,
        onsort: emitChange,
        onundo: emitChange,
        onredo: emitChange,
        onchangeheader: emitChange,
        onchangestyle: emitChange,
        onmerge: emitChange,
        onresizecolumn: emitChange,
        onresizerow: emitChange,
        worksheets: [
            {
                data: structuredClone(snapshot.data),
                columns: snapshot.columns.length
                    ? structuredClone(snapshot.columns)
                    : (props.columns.length ? props.columns : undefined),
                style: structuredClone(snapshot.style),
                mergeCells: structuredClone(snapshot.mergeCells),
                minDimensions: props.minDimensions,
                editable: !props.readonly,
                allowInsertRow: !props.readonly,
                allowInsertColumn: !props.readonly,
                allowDeleteRow: !props.readonly,
                allowDeleteColumn: !props.readonly,
                allowManualInsertRow: !props.readonly,
                allowManualInsertColumn: !props.readonly,
                columnResize: true,
                rowResize: false,
                tableOverflow: false,
            },
        ],
    }
}

/**
 * Construction applies the default cell style and fires onchangestyle, which
 * would otherwise schedule a save on every page load.
 */
function mountSheet() {
    suppressEmit = true
    try {
        worksheet = jspreadsheet(container.value, buildOptions())[0]
    } finally {
        suppressEmit = false
    }
}

function destroySheet() {
    if (container.value) {
        jspreadsheet.destroy(container.value, true)
    }
    worksheet = null
}

onMounted(mountSheet)

onBeforeUnmount(() => {
    if (debounceTimer) {
        flush()
    }
    destroySheet()
})

/**
 * Styles, merges and column widths cannot be pushed into a live worksheet the
 * way setData handles values, so an externally changed snapshot is applied by
 * rebuilding the instance.
 */
watch(
    () => props.modelValue,
    () => {
        if (!worksheet) return
        if (sameAsModel(currentSnapshot())) return

        suppressEmit = true
        destroySheet()
        mountSheet()
        suppressEmit = false
    },
    { deep: true }
)

defineExpose({ getSnapshot: currentSnapshot })
</script>

<style>
.spreadsheet-container .jss_container {
    font-family: inherit;
}
</style>
