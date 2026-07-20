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

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
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
let applyingExternal = false

function currentData() {
    return worksheet ? worksheet.getData() : []
}

function sameAsModel(data) {
    return JSON.stringify(data) === JSON.stringify(props.modelValue)
}

function flush() {
    clearTimeout(debounceTimer)
    debounceTimer = null

    const data = currentData()
    if (sameAsModel(data)) return

    emit('update:modelValue', data)
    emit('change', data)
}

function emitChange() {
    if (applyingExternal) return

    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(flush, props.debounce)
}

function buildOptions() {
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
        worksheets: [
            {
                data: props.modelValue?.length ? structuredClone(toRaw(props.modelValue)) : [],
                columns: props.columns.length ? props.columns : undefined,
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

onMounted(() => {
    worksheet = jspreadsheet(container.value, buildOptions())[0]
})

onBeforeUnmount(() => {
    if (debounceTimer) {
        flush()
    }
    if (container.value) {
        jspreadsheet.destroy(container.value, true)
    }
    worksheet = null
})

watch(
    () => props.modelValue,
    (val) => {
        if (!worksheet) return
        if (sameAsModel(currentData())) return

        applyingExternal = true
        worksheet.setData(structuredClone(toRaw(val ?? [])))
        applyingExternal = false
    },
    { deep: true }
)

defineExpose({ getData: currentData })
</script>

<style>
.spreadsheet-container .jss_container {
    font-family: inherit;
}
</style>
