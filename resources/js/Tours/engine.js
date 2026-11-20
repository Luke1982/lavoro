import { reactive, markRaw, nextTick, watch, onBeforeUnmount, getCurrentInstance } from 'vue'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'
import { hasPermission } from '@/Utilities/Utilities'
import { browserStorage } from '@/Utilities/browserStorage'
import { chaptersFor, tourById } from './catalog'
import { createNarrator } from './narrator'

/**
 * The rondleiding engine.
 *
 * Components say what they offer — named spots (`data-tour="planner.calendar"`
 * or a function registered with useTour) and named actions — and the engine only
 * ever uses those names. Each step runs its actions, waits for its spot, moves
 * the light there and starts the voice. An action changes what is on screen and
 * nothing else, and hands back how to undo itself: when a chapter ends, is
 * skipped or the rondleiding stops, every undo runs in reverse and the page is
 * as the person left it.
 */

const SPOT_TIMEOUT = 2500
const SKIP_TIMEOUT = 1200
const ACTION_TIMEOUT = 4000
const PAUSE_BETWEEN_STEPS = 1100
const INTERLUDE = 1800
const MUTED_KEY = 'lavoro_tour_muted'
/** The mark in an address that starts a rondleiding there: see addressStartingTour. */
const ADDRESS_MARK = 'rondleiding'

const actionRegistry = new Map()
const spotRegistry = new Map()
const pageCounts = new Map()
const narrator = createNarrator()

let stepToken = 0
let advanceTimer = null
let undoStack = []
let scrollSnapshot = null
let touchedScrollers = new Set()
let pending = null
const recorded = new Map()

export const tourState = reactive({
    picker: { open: false, tourId: null, auto: false, chapters: [], selected: [] },
    run: null,
    phase: 'idle',
    paused: false,
    muted: browserStorage.get(MUTED_KEY) === '1',
    target: null,
    aid: null,
    marks: [],
    caption: { text: '', progress: 0 },
    shown: null,
    narrated: false,
    interlude: null,
    finished: null,
    progress: {},
    nudge: 0,
})

/**
 * Offers spots and actions to the rondleiding of `tour` for as long as the
 * calling component is mounted. An action returns its own undo.
 *
 *     useTour('planner', { openCalendar: () => temporarily(open, true) })
 *     useTour('planner', {}, { spots: { 'planner.cell': () => cellElement() } })
 */
export function useTour(tour, actions = {}, options = {}) {
    const owner = Symbol(tour)
    Object.entries(actions).forEach(([name, fn]) => register(actionRegistry, tour + ':' + name, owner, fn))
    Object.entries(options.spots ?? {}).forEach(([name, fn]) => register(spotRegistry, name, owner, fn))

    if (getCurrentInstance()) {
        onBeforeUnmount(() => {
            unregister(actionRegistry, owner)
            unregister(spotRegistry, owner)
        })
    }
}

function register(registry, key, owner, fn) {
    registry.set(key, [...(registry.get(key) ?? []), { owner, fn }])
}

/** An action as offered by the component that came last, when more than one offers it. */
function actionFor(tourId, name) {
    return actionRegistry.get(tourId + ':' + name)?.at(-1)?.fn ?? null
}

function unregister(registry, owner) {
    for (const [key, entries] of registry) {
        const kept = entries.filter((entry) => entry.owner !== owner)
        kept.length ? registry.set(key, kept) : registry.delete(key)
    }
}

/** Sets `target` to `value` while a step needs it and returns the undo; null when it already held `value`. */
export function temporarily(target, value) {
    const before = target.value
    if (before === value) return null
    target.value = value

    return () => { target.value = before }
}

export function isDesktop() {
    return typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches
}

export function prefersReducedMotion() {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

export function audience() {
    const props = usePage().props ?? {}

    return {
        has: hasPermission,
        modules: props.tenant?.modules ?? [],
        device: isDesktop() ? 'desktop' : 'phone',
        props,
    }
}

export function availableChapters(tourId) {
    return chaptersFor(tourId, audience())
}

/* ── Spots ─────────────────────────────────────────────────────────────── */

export function isVisible(element) {
    if (!(element instanceof Element) || !element.isConnected) return false
    if (element.getClientRects().length === 0) return false
    const rect = element.getBoundingClientRect()
    if (rect.width < 1 || rect.height < 1) return false

    return getComputedStyle(element).visibility !== 'hidden'
}

function clippedRect(element) {
    let rect = element.getBoundingClientRect()
    let box = { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom }
    for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        const style = getComputedStyle(parent)
        if (style.overflowX === 'visible' && style.overflowY === 'visible') continue
        const outer = parent.getBoundingClientRect()
        box = {
            left: Math.max(box.left, outer.left),
            top: Math.max(box.top, outer.top),
            right: Math.min(box.right, outer.right),
            bottom: Math.min(box.bottom, outer.bottom),
        }
    }
    box = {
        left: Math.max(box.left, 0),
        top: Math.max(box.top, 0),
        right: Math.min(box.right, window.innerWidth),
        bottom: Math.min(box.bottom, window.innerHeight),
    }

    return box
}

/**
 * The part of an element that can actually be seen: cut to every scrolling box
 * around it and to the window. A planner day is far wider than the screen; the
 * light goes around the part of it that shows.
 */
export function visibleRect(element) {
    const box = clippedRect(element)

    return { left: box.left, top: box.top, right: box.right, bottom: box.bottom, width: Math.max(0, box.right - box.left), height: Math.max(0, box.bottom - box.top) }
}

function visibleShare(element) {
    const rect = element.getBoundingClientRect()
    const box = clippedRect(element)
    const area = Math.max(0, box.right - box.left) * Math.max(0, box.bottom - box.top)

    return area / Math.max(1, rect.width * rect.height)
}

/**
 * Whether a bar that stays put while the page scrolls -- the app's top bar, the
 * menu along the bottom, the question about notifications -- lies over an
 * element, near its top, middle or bottom. Then it is in view and still cannot
 * be seen.
 */
function isCovered(element) {
    const box = clippedRect(element)
    if (box.right <= box.left || box.bottom <= box.top) return false
    const x = (box.left + box.right) / 2

    return [0.15, 0.5, 0.85].some((share) => {
        const y = box.top + (box.bottom - box.top) * share
        const hit = document.elementsFromPoint(x, y).find((candidate) => !candidate.closest('[data-tour-layer]'))
        if (!hit || element.contains(hit) || hit.contains(element)) return false

        return [...ancestors(hit)].some((ancestor) => !ancestor.contains(element)
            && ['fixed', 'sticky'].includes(getComputedStyle(ancestor).position))
    })
}

function* ancestors(element) {
    for (let current = element; current && current !== document.body; current = current.parentElement) yield current
}

/** Every element a spot names, visible ones only, the ones already in view first. */
export function findSpotAll(name) {
    if (!name) return []
    const resolvers = spotRegistry.get(name) ?? []
    for (const resolver of [...resolvers].reverse()) {
        let found = null
        try {
            found = resolver.fn()
        } catch {
            found = null
        }
        const elements = (Array.isArray(found) ? found : [found]).filter(isVisible)
        if (elements.length) return elements
    }
    const elements = [...document.querySelectorAll('[data-tour="' + name + '"]')].filter(isVisible)
    const rank = (element) => {
        const share = visibleShare(element)

        return (share > 0.95 ? 0 : share > 0.5 ? 1 : 2) + (share > 0 && isCovered(element) ? 3 : 0)
    }

    return elements
        .map((element, index) => ({ element, index, rank: rank(element) }))
        .sort((a, b) => a.rank - b.rank || a.index - b.index)
        .map((entry) => entry.element)
}

export function findSpot(name) {
    return findSpotAll(name)[0] ?? null
}

function frame() {
    return new Promise((resolve) => requestAnimationFrame(() => resolve()))
}

function wait(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

async function settle() {
    await nextTick()
    await frame()
    await frame()
}

async function waitForSpot(name, token, timeout) {
    const deadline = performance.now() + timeout
    for (;;) {
        if (token !== stepToken) return []
        const elements = findSpotAll(name)
        if (elements.length) return elements
        if (performance.now() > deadline) return []
        await frame()
    }
}

/* ── Keeping the page as it was ────────────────────────────────────────── */

function takeScrollSnapshot() {
    const positions = new Map()
    positions.set(window, { left: window.scrollX, top: window.scrollY })
    document.querySelectorAll('*').forEach((element) => {
        if (element.scrollTop || element.scrollLeft) {
            positions.set(element, { left: element.scrollLeft, top: element.scrollTop })
        }
    })
    scrollSnapshot = positions
    touchedScrollers = new Set()
}

function restoreScroll() {
    if (!scrollSnapshot) return
    const targets = new Set([...scrollSnapshot.keys(), ...touchedScrollers])
    for (const target of targets) {
        const position = scrollSnapshot.get(target) ?? { left: 0, top: 0 }
        if (target === window) {
            window.scrollTo({ left: position.left, top: position.top, behavior: 'auto' })
        } else if (target.isConnected) {
            target.scrollTo({ left: position.left, top: position.top, behavior: 'auto' })
        }
    }
    scrollSnapshot = null
    touchedScrollers = new Set()
}

async function reveal(elements, token) {
    const element = elements[0]
    if (visibleShare(element) > 0.6 && !isCovered(element)) return
    for (let parent = element.parentElement; parent; parent = parent.parentElement) {
        if (parent.scrollHeight > parent.clientHeight || parent.scrollWidth > parent.clientWidth) touchedScrollers.add(parent)
    }
    touchedScrollers.add(window)
    const wide = element.getBoundingClientRect().width > window.innerWidth * 0.5
    element.scrollIntoView({ block: 'center', inline: wide ? 'nearest' : 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' })
    let last = null
    let still = 0
    const deadline = performance.now() + 1200
    while (performance.now() < deadline && token === stepToken) {
        await frame()
        const rect = element.getBoundingClientRect()
        const key = Math.round(rect.left) + ',' + Math.round(rect.top)
        still = key === last ? still + 1 : 0
        last = key
        if (still > 4) return
    }
}

/**
 * Scrolls just far enough that the first element of every mark is on screen
 * too, so a field further down a form is not lit up where nobody can see it.
 * A mark bigger than half the screen, such as a whole list, is in view anyway;
 * scrolling to it would only push the spot itself out of sight.
 */
async function revealMarks(marks, token) {
    for (const mark of marks) {
        const element = findSpotAll(mark.spot)[0]
        if (!element || element.getBoundingClientRect().height > window.innerHeight * 0.5) continue
        if (visibleShare(element) > 0.9 && !isCovered(element)) continue
        for (let parent = element.parentElement; parent; parent = parent.parentElement) {
            if (parent.scrollHeight > parent.clientHeight || parent.scrollWidth > parent.clientWidth) touchedScrollers.add(parent)
        }
        touchedScrollers.add(window)
        element.scrollIntoView({ block: isCovered(element) ? 'center' : 'nearest', inline: 'nearest', behavior: 'auto' })
        await frame()
        if (token !== stepToken) return
    }
}

async function runSafely(fn) {
    if (typeof fn !== 'function') return
    try {
        await fn()
    } catch (error) {
        console.warn('[rondleiding] Terugzetten mislukte', error)
    }
}

function withTimeout(promise, milliseconds) {
    return Promise.race([promise, wait(milliseconds).then(() => null)])
}

async function syncActions(tourId, desired) {
    const wanted = new Set(desired)
    for (let index = undoStack.length - 1; index >= 0; index--) {
        const entry = undoStack[index]
        if (!wanted.has(entry.key)) {
            undoStack.splice(index, 1)
            await runSafely(entry.undo)
        }
    }
    for (const name of desired) {
        if (undoStack.some((entry) => entry.key === name)) continue
        const action = actionFor(tourId, name)
        if (!action) {
            console.warn('[rondleiding] Actie "' + name + '" is op deze pagina niet aangemeld')
            continue
        }
        let undo = null
        try {
            undo = await withTimeout(Promise.resolve(action()), ACTION_TIMEOUT)
        } catch (error) {
            console.warn('[rondleiding] Actie "' + name + '" mislukte', error)
        }
        undoStack.push({ key: name, undo })
        await settle()
    }
}

async function undoAll() {
    const entries = undoStack.reverse()
    undoStack = []
    for (const entry of entries) await runSafely(entry.undo)
    await settle()
    restoreScroll()
}

/* ── Seen state ────────────────────────────────────────────────────────── */

async function loadProgress(tourId) {
    if (tourState.progress[tourId]?.loaded) return tourState.progress[tourId]
    try {
        const { data } = await axios.get('/api/tours/' + tourId)
        tourState.progress[tourId] = { loaded: true, started: !!data.started, seen: data.seen ?? {} }
        recorded.set(tourId, data.recorded ?? [])
    } catch {
        tourState.progress[tourId] = { loaded: true, started: true, seen: {} }
    }

    return tourState.progress[tourId]
}

async function saveProgress(tourId, payload) {
    try {
        await axios.get('/sanctum/csrf-cookie')
        const { data } = await axios.post('/api/tours/' + tourId, payload)
        tourState.progress[tourId] = { loaded: true, started: !!data.started, seen: data.seen ?? {} }
    } catch (error) {
        console.warn('[rondleiding] Voortgang niet opgeslagen', error)
    }
}

function markSeen(tourId, chapter) {
    const progress = tourState.progress[tourId]
    if (progress) progress.seen = { ...progress.seen, [chapter.id]: chapter.version }
    saveProgress(tourId, { started: true, seen: [{ chapter: chapter.id, version: chapter.version }] })
}

/* ── Pages ─────────────────────────────────────────────────────────────── */

/** A page offering a rondleiding says so while it is mounted; the button does it. */
export function announceTourPage(tourId) {
    const count = (pageCounts.get(tourId) ?? 0) + 1
    pageCounts.set(tourId, count)
    if (count === 1) pageReady(tourId)
}

export function leaveTourPage(tourId) {
    const count = Math.max(0, (pageCounts.get(tourId) ?? 0) - 1)
    pageCounts.set(tourId, count)
    if (count === 0 && tourState.run?.tourId === tourId) stopTour()
    if (count === 0 && tourState.picker.tourId === tourId) tourState.picker.open = false
}

function isOnPage(tourId) {
    return (pageCounts.get(tourId) ?? 0) > 0
}

/**
 * A window opened to go on with a rondleiding, such as the map from a list,
 * says which in its address. The mark comes out again at once, so reloading
 * the window does not start the rondleiding a second time. Inertia takes it
 * out, or its next visit would put the old address back.
 */
function askedInAddress(tourId) {
    const address = new URL(window.location.href)
    if (address.searchParams.get(ADDRESS_MARK) !== tourId) return false
    address.searchParams.delete(ADDRESS_MARK)
    router.replace({ url: address.pathname + address.search, preserveState: true, preserveScroll: true })

    return true
}

async function pageReady(tourId) {
    const asked = askedInAddress(tourId)
    const progress = await loadProgress(tourId)
    if (!isOnPage(tourId)) return
    if (asked) {
        await settle()
        startChapters(tourId, availableChapters(tourId).map((chapter) => chapter.id), { afterClick: true })

        return
    }
    if (pending?.tourId === tourId) {
        const request = pending
        pending = null
        await settle()
        request.chapterId ? startChapters(tourId, [request.chapterId]) : openPicker(tourId)

        return
    }
    if (!tourById(tourId)?.autoStart || progress.started || tourState.run || tourState.picker.open) return
    if (!availableChapters(tourId).length) return
    setTimeout(() => {
        if (isOnPage(tourId) && !tourState.run && !tourState.picker.open && !pending) openPicker(tourId, { auto: true })
    }, 900)
}

/**
 * Starts a rondleiding from anywhere: right here when its page is open, or after
 * going there. Used by the assistant and by a chapter that continues elsewhere.
 */
export function requestTour(tourId, chapterId = null) {
    const tour = tourById(tourId)
    if (!tour) return
    narrator.unlock()
    if (isOnPage(tourId)) {
        chapterId ? startChapters(tourId, [chapterId]) : openPicker(tourId)

        return
    }
    pending = { tourId, chapterId }
    router.visit(tour.page)
}

/* ── The picker ────────────────────────────────────────────────────────── */

export async function openPicker(tourId, { auto = false } = {}) {
    const progress = await loadProgress(tourId)
    const chapters = availableChapters(tourId)
    const selected = progress.started
        ? chapters.filter((chapter) => progress.seen[chapter.id] !== chapter.version).map((chapter) => chapter.id)
        : chapters.map((chapter) => chapter.id)
    tourState.picker = { open: true, tourId, auto, chapters, selected }
}

export function togglePickedChapter(chapterId) {
    const selected = tourState.picker.selected
    tourState.picker.selected = selected.includes(chapterId)
        ? selected.filter((id) => id !== chapterId)
        : [...selected, chapterId]
}

export function closePicker() {
    const { tourId } = tourState.picker
    tourState.picker.open = false
    if (tourId && !tourState.progress[tourId]?.started) {
        tourState.progress[tourId] = { ...tourState.progress[tourId], started: true }
        saveProgress(tourId, { started: true })
    }
}

/** From the picker's own button, so still inside the click that unlocks sound. */
export function startFromPicker() {
    const { tourId, selected } = tourState.picker
    narrator.unlock()
    closePicker()
    setTimeout(() => startChapters(tourId, selected), 260)
}

/* ── Running ───────────────────────────────────────────────────────────── */

/**
 * `afterClick` waits with the first chapter until somebody presses Begin. A
 * browser keeps a window silent until it has been clicked in, so a rondleiding
 * that goes on in a window of its own, such as the map opened from a list,
 * would otherwise start without its voice.
 */
export async function startChapters(tourId, chapterIds, { afterClick = false } = {}) {
    const chapters = availableChapters(tourId).filter((chapter) => chapterIds.includes(chapter.id))
    if (!chapters.length) {
        const page = usePage()
        if (page.props.flash) page.props.flash.error = 'Dit deel van de rondleiding is er niet voor jou, of niet op dit scherm.'

        return
    }
    if (tourState.run) await stopTour()
    tourState.run = { tourId, chapters, chapterIndex: -1, stepIndex: -1 }
    tourState.shown = null
    tourState.finished = null
    tourState.paused = false
    if (!afterClick) return goTo(0, 0, 1)
    tourState.phase = 'interlude'
    tourState.interlude = { chapterIndex: 0, chapter: chapters[0], afterClick: true }
}

async function goTo(chapterIndex, stepIndex, direction) {
    const token = ++stepToken
    const run = tourState.run
    if (!run) return
    clearTimeout(advanceTimer)
    narrator.stop()
    tourState.aid = null
    tourState.marks = []
    tourState.narrated = false
    tourState.interlude = null

    const chapter = run.chapters[chapterIndex]
    if (chapterIndex !== run.chapterIndex) {
        tourState.phase = 'moving'
        run.chapterIndex = chapterIndex
        run.stepIndex = stepIndex
        await undoAll()
        if (token !== stepToken) return
        takeScrollSnapshot()
        await loadProgress(run.tourId)
        await narrator.load(run.tourId, chapter, (recorded.get(run.tourId) ?? []).includes(chapter.id))
        if (token !== stepToken) return
    }
    run.stepIndex = stepIndex
    const step = chapter.steps[stepIndex]
    tourState.phase = 'moving'
    if (!scrollSnapshot) takeScrollSnapshot()

    const actions = [...(chapter.actions ?? []), ...[step.action ?? []].flat()]
    await syncActions(run.tourId, actions)
    if (token !== stepToken) return

    let elements = []
    if (step.spot) {
        elements = await waitForSpot(step.spot, token, step.skipIfMissing ? SKIP_TIMEOUT : SPOT_TIMEOUT)
        if (token !== stepToken) return
        if (!elements.length) {
            if (step.skipIfMissing) return skipMissing(direction)
            if (!step.fallback) console.warn('[rondleiding] Plek "' + step.spot + '" niet gevonden (' + run.tourId + ' / ' + chapter.id
                + ', stap ' + (stepIndex + 1) + '); de stap staat in het midden.')
        }
    }
    if (elements.length) await reveal(elements, token)
    if (token !== stepToken) return

    const marked = elements.length ? (step.marks ?? []).flatMap((mark) => findSpotAll(mark.spot)) : []
    await revealMarks(step.marks ?? [], token)
    if (token !== stepToken) return
    tourState.target = elements.length ? { name: step.spot, elements: markRaw([...elements.slice(0, 1), ...marked]) } : null
    const aid = !elements.length && step.spot && step.fallback ? step.fallback : step.aid
    tourState.aid = aid ? { ...aid, key: chapter.id + ':' + stepIndex } : null
    tourState.marks = (step.marks ?? []).map((mark, index) => ({
        key: chapter.id + ':' + stepIndex + ':' + index,
        spot: mark.spot,
        at: markMoment(step.say, mark.when),
    }))
    tourState.shown = { chapterIndex, stepIndex }
    tourState.caption = { text: step.say, progress: 0 }
    tourState.phase = 'step'
    narrate(token, step)
}

/**
 * How far into the spoken line a word falls, from 0 to 1. A mark lights up its
 * spot from that moment on, so "met het pijltje" lights the arrows as the word
 * is said. A word the line does not contain lights up from the start.
 */
export function markMoment(say, when) {
    const index = when ? say.toLowerCase().indexOf(when.toLowerCase()) : -1

    return index > 0 ? index / say.length : 0
}

/**
 * Runs `play` once the voice is `fraction` of the way into the first step of the
 * chapter, or at once when the rondleiding is further on: for a chapter action the
 * viewer should see happen under the light, not before it. Hands back how to stop
 * waiting.
 */
export function onceIntoChapter(fraction, play) {
    let played = false

    return watch(
        () => tourState.run && (tourState.run.stepIndex > 0 || (tourState.phase === 'step' && tourState.caption.progress >= fraction)),
        (due) => {
            if (!due || played) return
            played = true
            play()
        },
        { immediate: true },
    )
}

function narrate(token, step) {
    narrator.say(step.say, {
        muted: tourState.muted,
        onProgress: (fraction) => {
            if (token === stepToken) tourState.caption.progress = fraction
        },
        onEnd: () => {
            if (token !== stepToken) return
            tourState.narrated = true
            scheduleAdvance(token)
        },
    })
    if (tourState.paused) narrator.pause()
}

function scheduleAdvance(token) {
    clearTimeout(advanceTimer)
    const step = currentStep()
    if (tourState.paused || !step || step.cta) return
    advanceTimer = setTimeout(() => {
        if (token === stepToken && !tourState.paused) nextStep()
    }, PAUSE_BETWEEN_STEPS)
}

function skipMissing(direction) {
    const run = tourState.run
    const atVeryStart = run.chapterIndex === 0 && run.stepIndex === 0

    return direction < 0 && !atVeryStart ? previousStep() : nextStep()
}

export function currentChapter() {
    const run = tourState.run

    return run ? run.chapters[run.chapterIndex] ?? null : null
}

export function currentStep() {
    const chapter = currentChapter()

    return chapter ? chapter.steps[tourState.run.stepIndex] ?? null : null
}

export async function nextStep() {
    const run = tourState.run
    if (!run) return
    if (tourState.phase === 'interlude') return goTo(tourState.interlude.chapterIndex, 0, 1)
    const chapter = currentChapter()
    if (run.stepIndex < chapter.steps.length - 1) return goTo(run.chapterIndex, run.stepIndex + 1, 1)
    markSeen(run.tourId, chapter)
    if (run.chapterIndex < run.chapters.length - 1) return interlude(run.chapterIndex + 1)

    return finish()
}

export async function previousStep() {
    const run = tourState.run
    if (!run) return
    const chapter = currentChapter()
    if (tourState.phase === 'interlude') return chapter && goTo(run.chapterIndex, chapter.steps.length - 1, -1)
    if (run.stepIndex > 0) return goTo(run.chapterIndex, run.stepIndex - 1, -1)
    if (run.chapterIndex > 0) {
        const previous = run.chapters[run.chapterIndex - 1]

        return goTo(run.chapterIndex - 1, previous.steps.length - 1, -1)
    }
}

export async function skipChapter() {
    const run = tourState.run
    if (!run) return
    const next = (tourState.phase === 'interlude' ? tourState.interlude.chapterIndex : run.chapterIndex) + 1
    if (next < run.chapters.length) return interlude(next)

    return finish()
}

/** The Begin button between chapters: a click, so the voice may start in a window nobody clicked in yet. */
export function beginChapter() {
    narrator.unlock()

    return nextStep()
}

async function interlude(chapterIndex) {
    const token = ++stepToken
    clearTimeout(advanceTimer)
    narrator.stop()
    tourState.aid = null
    tourState.marks = []
    tourState.target = null
    tourState.phase = 'interlude'
    tourState.interlude = { chapterIndex, chapter: tourState.run.chapters[chapterIndex], startedAt: performance.now(), duration: INTERLUDE }
    await undoAll()
    await wait(INTERLUDE)
    if (token === stepToken && !tourState.paused) goTo(chapterIndex, 0, 1)
}

async function finish() {
    const token = ++stepToken
    const run = tourState.run
    clearTimeout(advanceTimer)
    narrator.stop()
    tourState.aid = null
    tourState.marks = []
    tourState.target = null
    await undoAll()
    if (token !== stepToken) return
    tourState.finished = { tourId: run.tourId, chapters: run.chapters.map((chapter) => chapter.title) }
    tourState.run = null
    tourState.phase = 'finished'
}

export async function stopTour() {
    ++stepToken
    clearTimeout(advanceTimer)
    narrator.stop()
    tourState.aid = null
    tourState.marks = []
    tourState.target = null
    tourState.interlude = null
    tourState.finished = null
    if (tourState.run) await undoAll()
    tourState.run = null
    tourState.shown = null
    tourState.phase = 'idle'
    tourState.paused = false
}

export function closeFinished() {
    tourState.finished = null
    tourState.phase = 'idle'
}

export function togglePause() {
    if (!tourState.run) return
    tourState.paused = !tourState.paused
    if (tourState.paused) {
        clearTimeout(advanceTimer)
        narrator.pause()

        return
    }
    if (tourState.phase === 'interlude') return goTo(tourState.interlude.chapterIndex, 0, 1)
    narrator.resume()
    if (tourState.narrated) scheduleAdvance(stepToken)
}

export function toggleMute() {
    tourState.muted = !tourState.muted
    browserStorage.set(MUTED_KEY, tourState.muted ? '1' : '0')
    narrator.setMuted(tourState.muted)
}

/**
 * A step that sends somebody elsewhere: this chapter counts as seen, the page is
 * put back, and the next rondleiding starts once its page is open. `action`
 * instead of `href` hands that to the page, such as opening the map in a window
 * of its own, which the page marks with `addressStartingTour`. It runs first,
 * while the click still counts as the person's own: a browser opens a window
 * for a click, not for a rondleiding.
 */
export async function followCallToAction(cta) {
    const run = tourState.run
    if (cta.action && run) actionFor(run.tourId, cta.action)?.()
    if (run) markSeen(run.tourId, currentChapter())
    narrator.unlock()
    await stopTour()
    if (cta.action) return
    if (cta.tour) pending = { tourId: cta.tour, chapterId: cta.chapter ?? null }
    router.visit(cta.href)
}

/** An address that starts this rondleiding once its page opens. */
export function addressStartingTour(address, tourId) {
    const url = new URL(address, window.location.origin)
    url.searchParams.set(ADDRESS_MARK, tourId)

    return url.pathname + url.search
}

export function nudgeCard() {
    tourState.nudge++
}
