<template>
    <SpotlightShell ref="shellRef" v-model="query" :open="open" intercept-arrows
        placeholder="Spring naar een pagina, klant, machine, werkbon…" @close="close"
        @enter="go(rows[activeIndex])" @up="move(-1)" @down="move(1)">
        <!--
            Full-strength lavorogreen is invisible on white, so it gets a dark tile
            to sit on. Against the near-black it reads the way it does in the
            sidebar instead of washing out.
        -->
        <template #icon>
            <span class="flex size-6 shrink-0 items-center justify-center rounded-md bg-lavoro-dark">
                <MagnifyingGlassIcon class="size-4 text-lavoro-green" />
            </span>
        </template>

        <template #after>
            <div class="max-h-[26rem] overflow-y-auto border-t border-slate-100">
                <div v-for="group in groups" :key="group.group">
                    <div
                        class="flex items-center gap-2 px-4 pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        <span class="h-3 w-1 rounded-full bg-lavoro-green ring-1 ring-slate-900/10"></span>
                        {{ group.group }}
                    </div>

                    <button v-for="hit in group.hits" :key="hit.href + hit.title" type="button"
                        :ref="(el) => (row_elements[hit.index] = el)"
                        class="relative flex w-full items-center gap-3 px-4 py-2 text-left transition-colors"
                        :class="isActive(hit) ? 'bg-lavoro-green' : 'hover:bg-slate-50'"
                        @mouseenter="activeIndex = hit.index" @click="go(hit)">
                        <component :is="hit.icon" v-if="hit.icon" class="size-4 shrink-0"
                            :class="isActive(hit) ? 'text-slate-900' : 'text-slate-400'" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-slate-900">{{ hit.title }}</span>
                            <!-- Muted grey vanishes on solid lavorogreen; on the selected row it goes dark. -->
                            <span v-if="hit.subtitle" class="block truncate text-xs"
                                :class="isActive(hit) ? 'text-slate-700' : 'text-slate-400'">{{ hit.subtitle }}</span>
                            <!--
                                The searcher guarantees the order, so the first
                                label can be emphasised as the stage without the
                                row having to work out what it is looking at.
                            -->
                            <span v-if="hit.meta?.length" class="mt-1 flex flex-wrap items-center gap-1">
                                <span v-for="(label, metaIndex) in hit.meta" :key="metaIndex"
                                    class="rounded px-1.5 py-0.5 text-[10px] font-medium" :class="metaIndex === 0
                                        ? 'bg-slate-900 text-lavoro-green'
                                        : isActive(hit) ? 'bg-white text-slate-700' : 'bg-slate-100 text-slate-600'">{{
                                            label }}</span>
                            </span>
                        </span>
                    </button>
                </div>

                <p v-if="error" class="px-4 py-6 text-sm text-red-600">{{ error }}</p>

                <!--
                    Shown even when a page already matched: the records are still
                    on their way, and a list that silently doubles in size a moment
                    after you started reading it is worse than a spinner.
                -->
                <div v-else-if="searching" class="flex items-center gap-2 px-4 py-3 text-sm text-slate-400">
                    <ArrowPathIcon class="size-4 shrink-0 animate-spin" />
                    <span>Aan het zoeken…</span>
                </div>

                <p v-else-if="!rows.length" class="px-4 py-6 text-sm text-slate-400">
                    Niets gevonden voor “{{ query.trim() }}”.
                </p>
            </div>
        </template>

        <template #footer-left>
            Je ziet alleen waar je bij mag.
        </template>

        <template #footer-right>
            <span class="hidden sm:inline">↑↓ kiezen · ↵ openen · </span>
            <kbd class="font-sans">{{ shortcutLabel }}</kbd> om te openen
        </template>
    </SpotlightShell>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { MagnifyingGlassIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import SpotlightShell from '@/Components/UI/SpotlightShell.vue'
import { useMenu } from '@/Composables/useMenu.js'
import { navIcon } from '@/Navigation/icons.js'
import { navigatorShortcutLabel, matchesNavigatorShortcut } from '@/Composables/useNavigator.js'
import { closeAssistant } from '@/Composables/useAssistant.js'

const SEARCH_DEBOUNCE_MS = 250
const MIN_TERM_LENGTH = 2

// Matches the max on GlobalSearchRequest. Sending more earns a 422 and shows the
// person an error for a rule they cannot see, so the extra is simply not sent.
const MAX_TERM_LENGTH = 100

const { destinations } = useMenu()

const open = ref(false)
const query = ref('')
const server_groups = ref([])
const searching = ref(false)
const error = ref('')
const activeIndex = ref(0)
const shellRef = ref(null)

// Indexed by row position, so the slots are reused rather than accumulated. Keyed
// by the row objects instead, this would keep every rebuilt list alive with the
// detached elements still hanging off it.
const row_elements = []
const shortcutLabel = navigatorShortcutLabel()

let debounce_timer = null
let abort_controller = null
let request_token = 0

/**
 * Every page in the sidebar this person is allowed to open, flattened. The
 * sidebar already decides who sees what, so reading it here means the spotlight
 * can never offer a chapter the menu hides.
 */
const chapters = computed(() =>
    destinations.value.map((destination) => ({
        title: destination.label,
        subtitle: destination.trail.length ? destination.trail.join(' · ') : null,
        href: destination.href,
        icon: navIcon(destination.icon),
    }))
)

/**
 * MySQL's collation matches "categorieen" against "Categorieën" without being
 * asked. The browser does not, and a chapter list that is pickier than the record
 * search behind it would be the more confusing half of the same box.
 */
const fold = (value) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()

/** Matched in the browser so a chapter appears on the keystroke, not after a round trip. */
const matched_chapters = computed(() => {
    const term = fold(query.value.trim())
    if (!term) return chapters.value

    return chapters.value.filter((chapter) => fold(chapter.title).includes(term))
})

/**
 * Every row is stamped with its position across all the groups. Carrying the
 * number is what lets the cursor, the hover and the scrolling all agree cheaply:
 * comparing the objects instead would mean a lookup per row per render, against
 * objects this list rebuilds from scratch on every navigation.
 */
const groups = computed(() => {
    const source = []
    if (matched_chapters.value.length) source.push({ group: 'Pagina\'s', hits: matched_chapters.value })
    source.push(...server_groups.value)

    let index = 0

    return source.map((group) => ({
        group: group.group,
        hits: group.hits.map((hit) => ({ ...hit, index: index++ })),
    }))
})

const rows = computed(() => groups.value.flatMap((group) => group.hits))

const isActive = (hit) => hit.index === activeIndex.value

function move(step) {
    if (!rows.value.length) return
    const next = activeIndex.value + step
    activeIndex.value = next < 0 ? rows.value.length - 1 : next % rows.value.length
    row_elements[activeIndex.value]?.scrollIntoView({ block: 'nearest' })
}

function go(hit) {
    if (!hit) return
    close()

    // Landing on the page you are already on keeps the same component alive, so
    // anything it reads once on mount — the planner reads its deep link there —
    // never sees the new query string. A real navigation is the only way in.
    if (hit.href.split('?')[0] === window.location.pathname) {
        window.location.href = hit.href
        return
    }

    router.visit(hit.href)
}

async function open_() {
    closeAssistant()
    query.value = ''
    server_groups.value = []
    error.value = ''
    activeIndex.value = 0
    open.value = true
    shellRef.value?.focus()
}

function close() {
    open.value = false
    clearTimeout(debounce_timer)
    abort_controller?.abort()
    searching.value = false
}

async function runSearch(term) {
    abort_controller = new AbortController()
    const token = ++request_token

    try {
        const { data } = await axios.get('/search/global', {
            params: { q: term },
            signal: abort_controller.signal,
        })
        if (token === request_token) server_groups.value = data.groups || []
    } catch (e) {
        // An aborted request was replaced by a newer one, so its empty result is
        // not an answer to anything the box is still asking.
        if (axios.isCancel(e) || token !== request_token) return

        server_groups.value = []
        // Silence here would read as "no records match", which is a different
        // and much more misleading statement than "the search did not run".
        error.value = e.response?.status === 429
            ? 'Even wachten, er wordt te snel gezocht.'
            : 'Zoeken in records lukt nu niet. Alleen pagina\'s worden getoond.'
    } finally {
        if (token === request_token) searching.value = false
    }
}

watch(query, (value) => {
    const term = value.trim().slice(0, MAX_TERM_LENGTH)
    clearTimeout(debounce_timer)
    abort_controller?.abort()
    error.value = ''

    // The cursor belongs on the best match for what is typed now, not on whatever
    // row happens to sit at the same offset in a list that just changed under it.
    activeIndex.value = 0

    if (term.length < MIN_TERM_LENGTH) {
        request_token++
        server_groups.value = []
        searching.value = false
        return
    }

    searching.value = true
    debounce_timer = setTimeout(() => runSearch(term), SEARCH_DEBOUNCE_MS)
})

/** A shrinking list must not leave the cursor pointing past the end of it. */
watch(rows, (value) => {
    if (activeIndex.value > value.length - 1) activeIndex.value = 0
})

function onKeydown(event) {
    if (matchesNavigatorShortcut(event)) {
        event.preventDefault()
        event.stopPropagation()
        open.value ? close() : open_()
    }
}

/**
 * Capture phase, because Ctrl+K is Firefox's own search bar. Cancelling it only
 * counts if this handler runs before anything else has a chance to let the event
 * through, and a listener in the bubble phase is not guaranteed to.
 */
onMounted(() => {
    window.addEventListener('keydown', onKeydown, { capture: true })
    window.addEventListener('navigator:open', open_)
    window.addEventListener('navigator:close', close)
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown, { capture: true })
    window.removeEventListener('navigator:open', open_)
    window.removeEventListener('navigator:close', close)
    clearTimeout(debounce_timer)
    abort_controller?.abort()
})
</script>
