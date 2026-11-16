import { reactive } from 'vue'
import axios from 'axios'

/**
 * Het bakje: what this person has set aside to paste somewhere else.
 *
 * One state for the whole app, so the bakje in the corner, a "put it on the
 * bakje" button and a drop on the planner all see the same thing. The server
 * keeps it per user and answers every change with the bakje as it now is.
 */
const DND_MIME = 'application/x-lavoro-tray'

const state = reactive({
    items: [],
    open: false,
    dragging: null,
    question: null,
    toast: null,
    busy: false,
})

let loading = null
let toastTimer = null
let toastId = 0

const reducedMotion = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

const showToast = (toast) => {
    clearTimeout(toastTimer)
    const duration = toast.undo ? 10000 : 6000
    state.toast = { ...toast, id: ++toastId, duration }
    toastTimer = setTimeout(() => { state.toast = null }, duration)
}

/**
 * One request to the bakje. A refusal or failure is shown in the bakje's own
 * message, with the server's reason when it gave one, and returns null.
 */
async function send(method, url, data = undefined) {
    try {
        await axios.get('/sanctum/csrf-cookie')
        const response = await axios({ method, url, data })
        return response.data
    } catch (error) {
        showToast({ message: error.response?.data?.message || 'Dat lukte niet. Probeer het nog een keer.', error: true })
        return null
    }
}

/**
 * A small chip that flies from the button that was clicked to the bakje in the
 * corner, so a person sees where their storingen went. When the bakje was empty
 * it is not on the screen yet; the chip then lands where it is about to appear.
 */
function flyToTray(source, count) {
    if (!source || reducedMotion()) return

    const from = source.getBoundingClientRect()
    const dock = document.querySelector('[data-tray-dock]')?.getBoundingClientRect()
    const right = dock ? dock.right : window.innerWidth - 24
    const bottom = dock ? dock.bottom : window.innerHeight - 96
    const originX = from.left + from.width / 2
    const originY = from.top + from.height / 2

    const chip = document.createElement('div')
    chip.setAttribute('aria-hidden', 'true')
    chip.className = 'pointer-events-none fixed z-[70] rounded-full bg-sidebar-bg px-3 py-1.5 text-sm font-bold whitespace-nowrap text-white shadow-2xl ring-2 ring-sidebar-indicator'
    chip.textContent = count === 1 ? 'Op het bakje' : `${count} op het bakje`
    chip.style.left = `${originX}px`
    chip.style.top = `${originY}px`
    document.body.appendChild(chip)

    const size = chip.getBoundingClientRect()
    const startX = -size.width / 2
    const startY = -size.height / 2
    const endX = right - size.width - originX
    const endY = bottom - size.height - originY

    chip.animate([
        { transform: `translate(${startX}px, ${startY}px) scale(0.6)`, opacity: 0 },
        { transform: `translate(${startX}px, ${startY - 12}px) scale(1.05)`, opacity: 1, offset: 0.15 },
        { transform: `translate(${(startX + endX) / 2}px, ${Math.min(startY, endY) - 80}px) scale(0.95)`, opacity: 1, offset: 0.55 },
        { transform: `translate(${endX}px, ${endY}px) scale(0.5)`, opacity: 0 },
    ], { duration: 750, easing: 'cubic-bezier(0.45, 0, 0.2, 1)' }).finished.finally(() => chip.remove())
}

export function loadTray() {
    loading ??= axios.get('/api/tray')
        .then(response => { state.items = response.data.items })
        .catch(() => {})
        .finally(() => { loading = null })
    return loading
}

export async function placeOnTray(kind, ids, source = null) {
    flyToTray(source, ids.length)
    const data = await send('post', '/api/tray', { kind, ids })
    if (!data) return
    state.items = data.items
    showToast({ message: ids.length === 1 ? 'Op het bakje gelegd.' : `${ids.length} op het bakje gelegd.` })
}

export async function removeFromTray(item) {
    const data = await send('delete', `/api/tray/${item.id}`)
    if (data) state.items = data.items
}

export async function pinOnTray(item, pinned) {
    const data = await send('patch', `/api/tray/${item.id}`, { pinned })
    if (data) state.items = data.items
}

export async function clearTray() {
    const data = await send('delete', '/api/tray')
    if (!data) return
    state.items = data.items
    state.open = false
}

/** Runs one request at a time: a second drop while the first is under way is ignored. */
async function whileBusy(work) {
    if (state.busy) return
    state.busy = true
    try {
        await work()
    } finally {
        state.busy = false
    }
}

const finish = (data, onDone) => {
    state.items = data.items
    state.open = false
    showToast({ message: data.message, undo: data.undo, onDone })
    onDone?.(data)
}

/**
 * Pastes bakje items on a target. When the server has a question first, it is
 * put on the screen and the paste waits for the answer.
 */
export function pasteFromTray(itemIds, target, onDone = null) {
    return whileBusy(async () => {
        const data = await send('post', '/api/tray/paste', { item_ids: itemIds, target })
        if (data?.question) {
            state.question = { ...data.question, itemIds, target, onDone }
        } else if (data) {
            finish(data, onDone)
        }
    })
}

export function answerTrayQuestion(answer, remove) {
    const question = state.question
    if (!question) return
    return whileBusy(async () => {
        const data = await send('post', '/api/tray/paste', {
            item_ids: question.itemIds,
            target: question.target,
            answer,
            remove,
        })
        state.question = null
        if (data) finish(data, question.onDone)
    })
}

export function dismissTrayQuestion() {
    state.question = null
}

export function undoTrayPaste() {
    const toast = state.toast
    if (!toast?.undo) return
    return whileBusy(async () => {
        const data = await send('post', '/api/tray/undo', { undo: toast.undo })
        if (!data) return
        state.items = data.items
        showToast({ message: data.message })
        toast.onDone?.(data)
    })
}

/**
 * Starts dragging bakje items: one item, or a whole stack. The bakje steps
 * aside a tick later, after the browser has taken its picture of what is
 * being dragged.
 */
export function startTrayDrag(event, kind, itemIds) {
    event.dataTransfer.setData(DND_MIME, JSON.stringify({ kind, item_ids: itemIds }))
    event.dataTransfer.effectAllowed = 'copy'
    setTimeout(() => { state.dragging = { kind, itemIds } }, 0)
}

export function endTrayDrag() {
    state.dragging = null
}

export function isTrayDrag(event) {
    return !!event.dataTransfer?.types?.includes(DND_MIME)
}

export function readTrayDrag(event) {
    try {
        return JSON.parse(event.dataTransfer.getData(DND_MIME))
    } catch {
        return null
    }
}

export function useTray() {
    return { state }
}
