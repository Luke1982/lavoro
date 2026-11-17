import { computed, onBeforeUnmount, reactive, toRaw, watchEffect } from 'vue'
import axios from 'axios'

/**
 * Het bakje: what this person has set aside to paste somewhere else.
 *
 * One state for the whole app, so the bakje in the corner, a "put it on the
 * bakje" button and a drop on the planner all see the same thing. The server
 * keeps it per user and answers every change with the bakje as it now is, and
 * says which kind of thing can go where, so no page has to know that itself.
 *
 * Something taken off the bakje to paste is carried: by dragging it, or, where
 * there is no dragging such as on a phone, by tapping Plakken and then tapping
 * the place it goes. Every place to drop on takes both.
 */
const DND_MIME = 'application/x-lavoro-tray'

const state = reactive({
    items: [],
    kinds: {},
    current: null,
    open: false,
    carrying: null,
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
 * A small chip that flies from the button that was clicked to the bakje, so a
 * person sees where it went: the corner on a computer, the button in the bar on
 * a phone. Whichever of them is on the screen is the one with a size. When the
 * bakje was empty it is not on the screen yet; the chip then lands where it is
 * about to appear.
 */
function flyToTray(source, count) {
    if (!source || reducedMotion()) return

    const from = source.getBoundingClientRect()
    const dock = [...document.querySelectorAll('[data-tray-dock]')]
        .map(el => el.getBoundingClientRect())
        .find(rect => rect.width > 0)
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
        .then(response => {
            state.items = response.data.items
            state.kinds = response.data.kinds
        })
        .catch(() => {})
        .finally(() => { loading = null })
    return loading
}

/** Puts records on the bakje. Says whether that worked; when it did not, the bakje has said why. */
export async function placeOnTray(kind, ids, source = null) {
    flyToTray(source, ids.length)
    const data = await send('post', '/api/tray', { kind, ids })
    if (!data) return false
    state.items = data.items
    showToast({ message: ids.length === 1 ? 'Op het bakje gelegd.' : `${ids.length} op het bakje gelegd.` })
    return true
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

/** "storing" or "3 storingen", in the words the server uses. */
export function trayCounted(kind, count) {
    const words = state.kinds[kind]
    if (!words) return ''
    return count === 1 ? words.one : `${count} ${words.many}`
}

/** Whether this kind of thing can be pasted on this kind of place. */
export function canPaste(kind, type) {
    return !!state.kinds[kind]?.places.includes(type)
}

/**
 * Starts dragging bakje items: one item, or a whole stack. The bakje steps
 * aside a tick later, after the browser has taken its picture of what is
 * being dragged.
 */
export function startTrayDrag(event, kind, itemIds) {
    event.dataTransfer.setData(DND_MIME, JSON.stringify({ kind, item_ids: itemIds }))
    event.dataTransfer.effectAllowed = 'copy'
    setTimeout(() => { state.carrying = { kind, itemIds, by: 'drag' } }, 0)
}

/** A drag that ends anywhere is over. Something picked up by tapping stays picked up. */
export function endTrayDrag() {
    if (state.carrying?.by === 'drag') state.carrying = null
}

/** Picks bakje items up to paste them by tapping the place they go. */
export function pickUpFromTray(kind, itemIds) {
    state.open = false
    state.carrying = { kind, itemIds, by: 'tap' }
}

export function putDownTray() {
    state.carrying = null
}

/** Pastes what was picked up on the place that was tapped. */
export function pasteCarried(target, onDone = null) {
    const carried = state.carrying
    state.carrying = null
    return pasteFromTray(carried.itemIds, target, onDone)
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

/**
 * Tells the bakje which record this page is about, so the bakje can offer to put
 * it on itself: on a phone there is no room for a button of its own on every
 * page, and the bakje is where a person goes to use it anyway.
 *
 *     useTrayRecord('ticket', () => props.ticket.id)
 *
 * The id is read as a getter because Inertia keeps the page component when it
 * moves from one storing to the next. Leaving, a page only clears its own record:
 * the next page may have set its own already.
 */
export function useTrayRecord(kind, id) {
    let mine = null

    watchEffect(() => {
        mine = { kind, id: id() }
        state.current = mine
    })

    onBeforeUnmount(() => {
        if (toRaw(state.current) === mine) state.current = null
    })
}

/**
 * The record this page is about, when it is a kind the bakje takes: what it is
 * called, and whether it already lies on the bakje.
 */
const currentRecord = computed(() => {
    const words = state.kinds[state.current?.kind]
    if (!words) return null
    const { kind, id } = state.current
    return {
        kind,
        id,
        one: words.one,
        onTray: state.items.some(item => item.kind === kind && item.record_id === id),
    }
})

/** Puts the record this page is about on the bakje. */
export function placeCurrentOnTray(source) {
    const record = currentRecord.value
    if (record) placeOnTray(record.kind, [record.id], source)
}

export function useTray() {
    return { state, currentRecord }
}
