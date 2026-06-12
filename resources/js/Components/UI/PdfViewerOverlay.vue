<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[60] flex flex-col bg-gray-100 dark:bg-slate-900">
            <div class="flex items-center h-12 px-2 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 shrink-0">
                <button
                    @click="$emit('update:open', false)"
                    class="flex items-center gap-1 text-sm font-medium text-gray-700 dark:text-slate-300 hover:text-gray-900 dark:hover:text-slate-100 min-h-[44px] px-2"
                >
                    <ChevronLeftIcon class="h-5 w-5 shrink-0" />
                    Terug
                </button>
                <span class="flex-1 text-center text-sm font-semibold text-gray-800 dark:text-slate-100 truncate px-2">
                    {{ title }}
                </span>
                <a
                    :href="url"
                    download
                    class="flex items-center min-h-[44px] px-2 text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-100"
                    title="Downloaden"
                >
                    <ArrowDownTrayIcon class="h-5 w-5" />
                </a>
            </div>

            <div class="flex-1 overflow-y-auto overscroll-contain flex flex-col items-center gap-4 py-4 px-2" style="-webkit-overflow-scrolling: touch">
                <div v-if="loading" class="flex-1 flex items-center justify-center">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                </div>

                <div v-else-if="error" class="flex-1 flex flex-col items-center justify-center gap-3 text-gray-500 dark:text-slate-400 text-sm">
                    <p>Dit document kon niet worden geladen.</p>
                    <a :href="url" download class="text-indigo-600 hover:underline">Probeer te downloaden</a>
                </div>

                <canvas
                    v-for="n in pageCount"
                    :key="n"
                    :ref="el => { if (el) canvases[n - 1] = el }"
                    class="max-w-full shadow-md rounded bg-white"
                />
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch, onUnmounted, nextTick } from 'vue'
import { ChevronLeftIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    open: { type: Boolean, required: true },
    url: { type: String, required: true },
    title: { type: String, default: '' },
})

defineEmits(['update:open'])

const loading = ref(false)
const error = ref(false)
const pageCount = ref(0)
const canvases = ref([])

// Evaluated statically by Vite so the worker file is included in the build output.
const workerSrc = new URL('pdfjs-dist/build/pdf.worker.mjs', import.meta.url).href

let pdfDocument = null
let loadGeneration = 0

async function loadPdf() {
    const generation = ++loadGeneration
    loading.value = true
    error.value = false
    pageCount.value = 0
    canvases.value = []

    try {
        const pdfjsLib = await import('pdfjs-dist')
        if (generation !== loadGeneration) return

        pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc

        const doc = await pdfjsLib.getDocument(props.url).promise
        if (generation !== loadGeneration) {
            doc.destroy()
            return
        }

        pdfDocument = doc
        pageCount.value = doc.numPages
        loading.value = false

        await nextTick()
        if (generation !== loadGeneration) return

        for (let i = 1; i <= doc.numPages; i++) {
            if (generation !== loadGeneration) return
            await renderPage(doc, i)
        }
    } catch {
        if (generation === loadGeneration) {
            loading.value = false
            error.value = true
        }
    }
}

async function renderPage(pdf, pageNum) {
    const page = await pdf.getPage(pageNum)
    const canvas = canvases.value[pageNum - 1]
    if (!canvas) return

    const containerWidth = canvas.parentElement?.clientWidth ?? window.innerWidth - 16
    const baseViewport = page.getViewport({ scale: 1 })
    const scale = Math.min(containerWidth / baseViewport.width, 2)
    const viewport = page.getViewport({ scale })

    canvas.width = viewport.width
    canvas.height = viewport.height

    await page.render({
        canvasContext: canvas.getContext('2d'),
        viewport,
    }).promise
}

function destroyPdf() {
    loadGeneration++
    if (pdfDocument) {
        pdfDocument.destroy()
        pdfDocument = null
    }
    pageCount.value = 0
    canvases.value = []
    error.value = false
}

watch(
    [() => props.open, () => props.url],
    ([isOpen]) => {
        if (isOpen) {
            document.documentElement.classList.add('overflow-hidden')
            loadPdf()
        } else {
            document.documentElement.classList.remove('overflow-hidden')
            destroyPdf()
        }
    }
)

onUnmounted(() => {
    document.documentElement.classList.remove('overflow-hidden')
    destroyPdf()
})
</script>
