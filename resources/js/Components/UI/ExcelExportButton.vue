<template>
    <button type="button" :disabled="disabled || downloading"
        class="inline-flex items-center justify-center gap-1.5 rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-900 dark:text-slate-100 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-40"
        @click="url ? download() : emit('click')">
        <LoaderCircle v-if="downloading" class="size-4 shrink-0 animate-spin" />
        <FileSpreadsheet v-else class="size-4 shrink-0" />
        Exporteren
    </button>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { downloadBlob } from '@/Utilities/download.js'
import { requestFailureMessage } from '@/Utilities/requestFailure.js'
import { FileSpreadsheet, LoaderCircle } from '@lucide/vue'

/**
 * Every Excel export looks the same: a plain white button with a spreadsheet icon
 * that spins until the file is there. Given a url it downloads the file itself;
 * without one it only says it was clicked, for an export that first asks what to
 * export. The viewer's timezone goes along, so the times in the file are the ones
 * they saw on the screen, and the file keeps the name the server gave it.
 */
const props = defineProps({
    url: { type: String, default: null },
    params: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['click', 'downloaded'])

const page = usePage()
const downloading = ref(false)

async function download() {
    downloading.value = true
    try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
        const response = await axios.get(props.url, {
            params: { ...props.params, tz },
            responseType: 'blob',
        })
        await downloadBlob(response.data, serverFilename(response) ?? 'export.xlsx')
        emit('downloaded')
    } catch (error) {
        page.props.flash.error = requestFailureMessage(await withReadableBody(error), 'Exporteren is mislukt')
    } finally {
        downloading.value = false
    }
}

function serverFilename(response) {
    const match = (response.headers['content-disposition'] ?? '').match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)

    return match ? decodeURIComponent(match[1]) : null
}

/**
 * A refusal arrives as a blob, like the file would have; the reason in it is JSON.
 */
async function withReadableBody(error) {
    const data = error?.response?.data

    if (data instanceof Blob) {
        try {
            error.response.data = JSON.parse(await data.text())
        } catch {
            error.response.data = null
        }
    }

    return error
}
</script>
