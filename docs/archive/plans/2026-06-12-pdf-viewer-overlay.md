# PDF Viewer Overlay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `target="_blank"` PDF links in the document widget with an in-app full-screen overlay powered by PDF.js, giving Safari PWA users a back button to return to the app.

**Architecture:** `pdfjs-dist` is installed as a dependency and loaded via dynamic `import()` so it is excluded from the initial bundle. A new `PdfViewerOverlay.vue` component renders PDF pages on stacked `<canvas>` elements inside a full-screen `position:fixed` overlay. `DocumentUploadComponent.vue` is modified to detect `.pdf` extensions, open the overlay for PDFs, and use plain `download` anchor links for all other file types.

**Tech Stack:** Vue 3, pdfjs-dist 4.x, Vite, Tailwind CSS v4, HeroIcons

---

## File Map

| Action | Path | Responsibility |
|--------|------|---------------|
| Create | `resources/js/Components/UI/PdfViewerOverlay.vue` | Full-screen PDF viewer with header + canvas pages |
| Modify | `resources/js/Components/DocumentUploadComponent.vue` | Intercept PDF clicks, open overlay; download attr for others |

---

### Task 1: Install pdfjs-dist

**Files:**
- Modify: `package.json` (via npm)

- [ ] **Step 1: Install the package**

```bash
npm install pdfjs-dist
```

Expected output: pdfjs-dist added to `dependencies` in `package.json`, no errors.

- [ ] **Step 2: Verify the worker file exists**

```bash
ls node_modules/pdfjs-dist/build/pdf.worker.mjs
```

Expected output: the file path is printed (no "No such file" error).

---

### Task 2: Create PdfViewerOverlay.vue

**Files:**
- Create: `resources/js/Components/UI/PdfViewerOverlay.vue`

- [ ] **Step 1: Create the component**

Create `resources/js/Components/UI/PdfViewerOverlay.vue` with the full content below:

```vue
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

            <div class="flex-1 overflow-y-auto overscroll-contain flex flex-col items-center gap-4 py-4 px-2">
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

async function loadPdf() {
    loading.value = true
    error.value = false
    pageCount.value = 0
    canvases.value = []

    try {
        const pdfjsLib = await import('pdfjs-dist')
        pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc

        pdfDocument = await pdfjsLib.getDocument(props.url).promise
        pageCount.value = pdfDocument.numPages
        loading.value = false

        await nextTick()

        for (let i = 1; i <= pdfDocument.numPages; i++) {
            await renderPage(pdfDocument, i)
        }
    } catch {
        loading.value = false
        error.value = true
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
    if (pdfDocument) {
        pdfDocument.destroy()
        pdfDocument = null
    }
    pageCount.value = 0
    canvases.value = []
    error.value = false
}

watch(
    () => props.open,
    (isOpen) => {
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
```

- [ ] **Step 2: Verify no import errors**

Run the dev server and check the terminal for Vite compilation errors:

```bash
npm run dev
```

Expected: Vite starts without errors. (Stop with Ctrl+C after confirming.)

---

### Task 3: Modify DocumentUploadComponent.vue

**Files:**
- Modify: `resources/js/Components/DocumentUploadComponent.vue`

The goal is:
- PDFs (`.pdf` extension): clicking title or filename opens the overlay
- Non-PDFs: clicking title or filename triggers a browser download via `download` attribute on `<a>`
- The `EditableTextField` (title column, users with `document.update`) is unchanged — they use the filename column to open/download

- [ ] **Step 1: Add the overlay import and reactive state**

In the `<script setup>` block, add the import for `PdfViewerOverlay` and a `viewingDoc` ref. The final imports section should look like this (add only the two new lines, leave existing imports untouched):

```js
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { TrashIcon } from '@heroicons/vue/24/solid';
import { hasPermission, nlDate } from '@/Utilities/Utilities';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import PdfViewerOverlay from '@/Components/UI/PdfViewerOverlay.vue';  // ADD
```

After the existing `const isDragging = ref(false)` line, add:

```js
const viewingDoc = ref(null);
const isPdf = (doc) => doc.name.toLowerCase().endsWith('.pdf');
```

- [ ] **Step 2: Replace the title column anchor (v-else branch)**

Find this block in the template (lines ~23–26):

```html
<a v-else :href="`/documents/${doc.id}/download`" target="_blank"
    class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 truncate block">
    {{ doc.title || doc.name }}
</a>
```

Replace it with:

```html
<button v-else-if="isPdf(doc)"
    @click="viewingDoc = doc"
    class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 truncate block text-left w-full">
    {{ doc.title || doc.name }}
</button>
<a v-else :href="`/documents/${doc.id}/download`" download
    class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 truncate block">
    {{ doc.title || doc.name }}
</a>
```

- [ ] **Step 3: Replace the filename column anchor**

Find this block (lines ~31–34):

```html
<a :href="`/documents/${doc.id}/download`" target="_blank"
    class="text-xs text-gray-600 dark:text-gray-400 hover:underline truncate block">
    {{ doc.name }}
</a>
```

Replace it with:

```html
<button v-if="isPdf(doc)"
    @click="viewingDoc = doc"
    class="text-xs text-gray-600 dark:text-gray-400 hover:underline truncate block text-left w-full">
    {{ doc.name }}
</button>
<a v-else :href="`/documents/${doc.id}/download`" download
    class="text-xs text-gray-600 dark:text-gray-400 hover:underline truncate block">
    {{ doc.name }}
</a>
```

- [ ] **Step 4: Mount the overlay at the bottom of the template**

Before the closing `</div>` of the root element (line ~67, just before `</template>`), add:

```html
<PdfViewerOverlay
    :open="viewingDoc !== null"
    :url="viewingDoc ? `/documents/${viewingDoc.id}/download` : ''"
    :title="viewingDoc ? (viewingDoc.title || viewingDoc.name) : ''"
    @update:open="viewingDoc = null"
/>
```

- [ ] **Step 5: Verify the build compiles cleanly**

```bash
npm run build
```

Expected: build completes without errors. You will see pdfjs-dist appear as a separate chunk in the output (lazy-loaded).

---

### Task 4: Manual verification

- [ ] **Step 1: Start the dev stack**

```bash
composer run dev
```

- [ ] **Step 2: Open a page with the document widget in the browser**

Navigate to any resource that has a document widget (e.g. a ServiceOrder or Customer show page). Open browser DevTools network tab.

- [ ] **Step 3: Verify PDF opens in overlay**

Upload or locate a PDF document. Click its name or filename. Expected:
- The overlay slides in covering the full viewport
- A "← Terug" button appears top-left
- A download icon appears top-right
- The PDF renders page by page in the scroll area
- The network tab shows pdfjs-dist chunk loaded on demand (not in the initial page load)

- [ ] **Step 4: Verify non-PDF downloads**

Click a `.docx` or other non-PDF document. Expected:
- Browser initiates a file download
- The page does not navigate away

- [ ] **Step 5: Verify back button closes the overlay**

With the overlay open, click "← Terug". Expected: overlay closes, the page beneath is unchanged.

- [ ] **Step 6: Verify download button in overlay**

With the overlay open, click the download icon in the header. Expected: the PDF file downloads to the device.

- [ ] **Step 7: Run linter**

```bash
npm run fix:eslint
```

Expected: no unfixable errors.
