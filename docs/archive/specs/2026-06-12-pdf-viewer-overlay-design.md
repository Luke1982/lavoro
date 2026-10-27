# PDF Viewer Overlay — Design Spec
Date: 2026-06-12

## Problem

PWA users on Safari get locked on a dead-end screen when tapping a PDF document link in the document widget. The current implementation opens `/documents/{id}/download` with `target="_blank"`, which in Safari PWA mode navigates the app away from its context with no back button.

## Solution

Replace PDF link behaviour with an in-app full-screen overlay that renders the PDF using PDF.js. Non-PDF documents continue to trigger a browser download. The overlay has a prominent "← Terug" button so users can always return.

## Scope

- PDFs only (detected by `.pdf` file extension on `doc.name`)
- All pages that embed `DocumentUploadComponent` benefit automatically
- No new Laravel routes or controllers required

## Components

### `resources/js/Components/UI/PdfViewerOverlay.vue` (new)

**Responsibility:** Render a PDF document full-screen and allow the user to dismiss it.

**Props:**
- `open: Boolean` — controls visibility
- `url: String` — the download URL for the PDF (e.g. `/documents/{id}/download`)
- `title: String` — document title shown in the header

**Emits:**
- `update:open` with `false` — parent closes the overlay

**Layout:**
```
┌─────────────────────────────────────┐  ← position: fixed; inset: 0; z-index: 60
│ [← Terug]   Documenttitel   [⬇ ]   │  ← sticky header, h-12, bg-white/dark
├─────────────────────────────────────┤
│                                     │
│   [canvas page 1]                   │  ← overflow-y-auto scrollable body
│   [canvas page 2]                   │
│   ...                               │
│                                     │
└─────────────────────────────────────┘
```

**PDF.js integration:**
- `pdfjs-dist` is imported dynamically inside the component (`import('pdfjs-dist')`) so it is excluded from the initial bundle and only loaded on first PDF open.
- Worker is set via `GlobalWorkerOptions.workerSrc` pointing to `pdfjs-dist/build/pdf.worker.mjs` using Vite's `new URL(..., import.meta.url)` pattern.
- On `open` becoming `true`, load the PDF from `url` using `pdfjsLib.getDocument(url)`. Render each page sequentially onto individual `<canvas>` elements at a scale that fills the container width.
- On `open` becoming `false`, destroy the PDF document instance and clear canvas refs to free memory.

**States:**
- Loading: spinner centered in the body while `getDocument` resolves
- Rendered: pages visible, user can scroll
- Error: friendly message ("Dit document kon niet worden geladen.") with a download fallback link

**Accessibility / mobile:**
- Header "← Terug" button has sufficient tap target (min 44px height)
- `overflow-y: auto; -webkit-overflow-scrolling: touch` on the scroll container for smooth iOS scroll
- Body scroll is locked (`overflow: hidden` on `<html>`) while overlay is open to prevent background scroll-through

### `resources/js/Components/DocumentUploadComponent.vue` (modified)

**Changes:**
1. Add `viewingDoc` ref (initially `null`). When set to a doc object, the overlay opens.
2. Add `isPdf(doc)` helper: `doc.name.toLowerCase().endsWith('.pdf')`.
3. Both the title column anchor and the filename column anchor change behaviour:
   - If `isPdf(doc)`: prevent default, set `viewingDoc = doc`
   - Otherwise: `window.location.href = downloadUrl` (triggers download without `target="_blank"`)
4. Mount `PdfViewerOverlay` at the bottom of the template, bound to `viewingDoc`:
   ```
   :open="viewingDoc !== null"
   :url="`/documents/${viewingDoc?.id}/download`"
   :title="viewingDoc?.title || viewingDoc?.name"
   @update:open="viewingDoc = null"
   ```

**Note:** The `v-else` branch (non-editable link, for users without `document.update` permission) also gets the same intercept logic.

## Dependencies

- `pdfjs-dist` — install via `npm install pdfjs-dist`. Current stable version as of spec date: 4.x.

## Error handling

- Network error fetching the PDF: show error state with download link fallback
- Corrupt / unreadable PDF: PDF.js rejects the promise; same error state
- Very large PDFs (100+ pages): pages render lazily as the user scrolls (intersection observer or render-on-demand) to avoid memory exhaustion — implement if pages > 20, otherwise render all upfront

## Out of scope

- Pinch-to-zoom / custom zoom controls (not required for v1)
- Non-PDF document types (Word, Excel, etc.) — download only
- Text selection / annotation within the viewer
- Page number indicator in header (nice-to-have, add only if trivial)
