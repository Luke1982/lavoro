<template>
    <IndexHeaderComponent title="Checklists voor werkbontaken"
        subtitle="Stel per werkbontaak in welke checks de monteur afvinkt." :show-search="false">
        <template #actions>
            <TourButton tour="checklists" />
        </template>
    </IndexHeaderComponent>

    <BoxComponent class="md:hidden">
        <p class="text-sm text-gray-600 dark:text-slate-300">
            Open deze pagina op een groter scherm om checklists te beheren.
        </p>
    </BoxComponent>

    <BoxComponent class="hidden md:block">
        <p v-if="example" class="mb-3 text-xs italic text-amber-700 dark:text-amber-400">
            Voorbeeld uit de rondleiding: zo komen je checks, checklists en taken hier te staan.
        </p>
        <div ref="board" data-tour="checklists.board" class="relative grid h-[calc(100vh-15rem)] min-h-[28rem] grid-cols-3 gap-x-20"
            v-on="handlers" @click="popover = null">
            <EditorLines :links="links" :highlighted="connected" @line-click="openPopover" />

            <EditorColumn v-model:search="search.check" v-model:draft="drafts.check" title="Checks" data-tour="checklists.checks"
                :count="library.checks.length" :add-label="canManage ? '+ Nieuwe check' : null" search-placeholder="Zoek een check..."
                :error="errors.check" @add="title => create('check', title)">
                <EditorCard v-for="check in visible.check" :key="'check:' + check.id" v-bind="card('check:' + check.id)"
                    kind="check" :title="check.title" :editable="canManage && check.id > 0" :draggable="canManage && check.id > 0"
                    @rename="title => rename('check', check.id, title)" @delete="deleting = 'check:' + check.id" />
                <p v-if="!visible.check.length" class="text-sm text-gray-400">Geen checks gevonden.</p>
            </EditorColumn>

            <EditorColumn v-model:search="search.checklist" v-model:draft="drafts.checklist" title="Checklists" data-tour="checklists.lists"
                :count="library.checklists.length" :add-label="canManage ? '+ Nieuwe checklist' : null" search-placeholder="Zoek een checklist..."
                :error="errors.checklist" @add="title => create('checklist', title)">
                <EditorCard v-for="list in visible.checklist" :key="'checklist:' + list.id"
                    v-bind="card('checklist:' + list.id)" kind="checklist" :title="list.title" :editable="canManage && list.id > 0"
                    openable :draggable="canManage && list.id > 0" @rename="title => rename('checklist', list.id, title)"
                    @delete="deleting = 'checklist:' + list.id">
                    <CardContents :items="contents.checklist(list)" :readonly="!canManage || list.id < 0"
                        @reorder="ids => reorder('check', ids)" @set-required="(item, value) => setRequired('check', item.linkId, value)" />
                </EditorCard>
                <p v-if="!visible.checklist.length" class="text-sm text-gray-400">Geen checklists gevonden.</p>
            </EditorColumn>

            <EditorColumn v-model:search="search.task" title="Taken" :count="library.tasks.length" data-tour="checklists.tasks"
                search-placeholder="Zoek een taak...">
                <EditorCard v-for="task in visible.task" :key="'task:' + task.id" v-bind="card('task:' + task.id)"
                    kind="task" :title="task.title" openable>
                    <div class="space-y-3">
                        <CardContents heading="Checklists" :items="contents.taskChecklists(task)" :readonly="!canManage || task.id < 0" @reorder="ids => reorder('checklist', ids)"
                            @set-required="(item, value) => setRequired('checklist', item.linkId, value)" />
                        <CardContents heading="Losse checks" :items="contents.taskChecks(task)" :readonly="!canManage || task.id < 0"
                            @reorder="ids => reorder('check', ids)"
                            @set-required="(item, value) => setRequired('check', item.linkId, value)" />
                    </div>
                </EditorCard>
                <p v-if="!visible.task.length" class="text-sm text-gray-400">
                    Geen taken gevonden. Taken maak je aan onder Werkbonnen → Taken.
                </p>
            </EditorColumn>

            <LinkPopover v-if="popover" data-tour="checklists.popover" :link="popover.link" :label="popover.label" :position="popover.position"
                @set-required="value => setRequired(popover.link.kind, popover.link.id, value)" @unlink="unlink" />
        </div>
    </BoxComponent>

    <ModalDialog :open="!!deleting" title="Verwijderen?" max-width-class="sm:max-w-md"
        @update:open="value => { if (!value) deleting = null }">
        <p v-if="deleting" class="text-sm text-gray-600 dark:text-slate-300">
            "{{ titleOf(deleting) }}" {{ usageSentence(deleting) }}. Werkbonnen die er al mee werken houden hun eigen kopie.
        </p>
        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" class="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300" @click="deleting = null">
                    Annuleren
                </button>
                <button type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-700"
                    @click="confirmDelete">
                    Verwijderen
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import CardContents from '@/Components/TaskChecks/CardContents.vue'
import EditorCard from '@/Components/TaskChecks/EditorCard.vue'
import EditorColumn from '@/Components/TaskChecks/EditorColumn.vue'
import EditorLines from '@/Components/TaskChecks/EditorLines.vue'
import LinkPopover from '@/Components/TaskChecks/LinkPopover.vue'
import { useChecklistGraph } from '@/Composables/useChecklistGraph.js'
import { useLinkDrag } from '@/Composables/useLinkDrag.js'
import { hasPermission } from '@/Utilities/Utilities'
import TourButton from '@/Components/Tour/TourButton.vue'
import { useTour } from '@/Composables/useTour'

/**
 * Which checks a mechanic ticks off on which werkbontaak: checks on the left,
 * reusable checklists in the middle, task templates on the right. A check sits
 * in a checklist or directly on a task; a checklist sits on a task.
 */
const props = defineProps({
    checks: { type: Array, default: () => [] },
    checklists: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
})

const URLS = {
    check: '/serviceordertaskchecks',
    checklist: '/serviceordertaskchecklists',
    checkLink: '/serviceordertaskchecklinks',
    checklistLink: '/serviceordertaskchecklistlinks',
}

const page = usePage()
const canManage = hasPermission('serviceordertask.update')
/**
 * Shown by the rondleiding when the library still lacks checks, checklists or
 * tasks: a small example beside what is there, with negative ids, that nothing
 * can link, edit or save.
 */
const example = ref(null)
const library = reactive({
    checks: computed(() => [...props.checks, ...(example.value?.checks ?? [])]),
    checklists: computed(() => [...props.checklists, ...(example.value?.checklists ?? [])]),
    tasks: computed(() => [...props.tasks, ...(example.value?.tasks ?? [])]),
})

const { links, titleOf, usageSentence, meta, connectedTo, contents } = useChecklistGraph(library)

const board = ref(null)

const search = reactive({ check: '', checklist: '', task: '' })
const drafts = reactive({ check: '', checklist: '' })
const errors = reactive({ check: '', checklist: '' })
const selectedKey = ref(null)
const openKeys = reactive(new Set())
const deleting = ref(null)
const popover = ref(null)

const matches = (title, query) => title.toLowerCase().includes(query.trim().toLowerCase())

const visible = computed(() => ({
    check: library.checks.filter(check => matches(check.title, search.check)),
    checklist: library.checklists.filter(list => matches(list.title, search.checklist)),
    task: library.tasks.filter(task => matches(task.title, search.task)),
}))

const connected = computed(() => (selectedKey.value ? connectedTo(selectedKey.value) : null))

/** What every card needs from the page: its key, its line, and how it stands to the selection. */
const card = key => ({
    'data-card': key,
    meta: meta(key),
    selected: selectedKey.value === key,
    faded: connected.value !== null && !connected.value.has(key),
    open: openKeys.has(key),
    dropActive: dropKey.value === key,
    onSelect: () => select(key),
    onToggleOpen: () => (openKeys.has(key) ? openKeys.delete(key) : openKeys.add(key)),
})

function select(key) {
    popover.value = null
    selectedKey.value = selectedKey.value === key ? null : key
}

const linkUrl = kind => (kind === 'checklist' ? URLS.checklistLink : URLS.checkLink)

/**
 * Every change saves through here: the page keeps its scroll, its selection and
 * its open cards. Nothing that names an example card ever leaves the page.
 */
function send(method, url, data = {}, options = {}) {
    if (/\/-\d/.test(url) || Object.values(data).flat().some(value => typeof value === 'number' && value < 0)) return

    router[method](url, data, {
        preserveScroll: true,
        preserveState: true,
        only: ['checks', 'checklists', 'tasks', 'flash'],
        onError: found => { page.props.flash.error = Object.values(found)[0] },
        ...options,
    })
}

function create(kind, title) {
    errors[kind] = ''
    send('post', URLS[kind], { title }, {
        onSuccess: () => { drafts[kind] = '' },
        onError: found => { errors[kind] = found.title ?? Object.values(found)[0] },
    })
}

const rename = (kind, id, title) => send('patch', `${URLS[kind]}/${id}`, { title })
const reorder = (kind, ids) => send('post', `${linkUrl(kind)}/reorder`, { ids })
const setRequired = (kind, id, value) => {
    popover.value = null
    send('patch', `${linkUrl(kind)}/${id}`, { is_required: value })
}

function confirmDelete() {
    const [kind, id] = deleting.value.split(':')

    if (selectedKey.value === deleting.value) selectedKey.value = null
    deleting.value = null
    send('delete', `${URLS[kind]}/${id}`)
}

const kindOf = key => key.split(':')[0]
const idOf = key => Number(key.split(':')[1])
const TARGETS = { check: ['checklist', 'task'], checklist: ['task'] }

const { drag, dropKey, point, handlers } = useLinkDrag(board, {
    canStart: key => kindOf(key) in TARGETS && idOf(key) > 0,
    canLink: (from, to) => (TARGETS[kindOf(from)] ?? []).includes(kindOf(to)) && idOf(to) > 0
        && !links.value.some(link => link.from === from && link.to === to),
    onLink: (from, to) => {
        popover.value = null

        if (kindOf(from) === 'check') {
            send('post', URLS.checkLink, { check_id: idOf(from), target: kindOf(to), target_id: idOf(to) })
        } else {
            send('post', URLS.checklistLink, { checklist_id: idOf(from), task_id: idOf(to) })
        }
    },
})

provide('linkDrag', drag)

function openPopover(link, event) {
    if (!canManage || link.id < 0) return

    showPopover(link, point(event))
}

/** The window for one line, beside a point on the board. */
function showPopover(link, { x, y }) {
    popover.value = {
        link,
        label: titleOf(link.from) + ' → ' + titleOf(link.to),
        position: { x: Math.min(x + 8, board.value.clientWidth - 264), y: y + 8 },
    }
}

function unlink() {
    const { kind, id } = popover.value.link

    popover.value = null
    send('delete', `${linkUrl(kind)}/${id}`)
}

const closeOnEscape = event => { if (event.key === 'Escape') popover.value = null }

/** The task the rondleiding shows: the first one with something linked to it. */
const shownTask = () => links.value.find(link => kindOf(link.to) === 'task')?.to ?? (library.tasks[0] ? 'task:' + library.tasks[0].id : null)
const cardElement = key => (key ? board.value?.querySelector(`[data-card="${key}"]`) : null)

/**
 * The checklists rondleiding selects a task, opens a line and opens a card, and
 * puts each back. It never links, renames or saves anything.
 */
/** A check in a checklist, the checklist on a task, and a loose check straight on the task. */
function exampleLibrary() {
    const link = (id, field, target, is_required) => ({ id, [field]: target, is_required })

    return {
        checks: [
            { id: -1, title: 'Filters gereinigd (voorbeeld)' },
            { id: -2, title: 'Leidingen afgeperst (voorbeeld)' },
            { id: -3, title: 'Foto van de buitenunit (voorbeeld)' },
        ],
        checklists: [
            { id: -1, title: 'Oplevering (voorbeeld)', links: [link(-1, 'check_id', -1, true), link(-2, 'check_id', -2, false)] },
        ],
        tasks: [
            {
                id: -1,
                title: 'Airco installeren (voorbeeld)',
                checklist_links: [link(-3, 'checklist_id', -1, true)],
                check_links: [link(-4, 'check_id', -3, false)],
            },
        ],
    }
}

useTour('checklists', {
    showExample: () => {
        if (example.value || (props.checks.length && props.checklists.length && props.tasks.length)) return null
        example.value = exampleLibrary()

        return () => { example.value = null }
    },
    selectTask: () => {
        const key = shownTask()
        const previous = selectedKey.value
        if (!key || previous === key) return null
        select(key)

        return () => { if (selectedKey.value === key) selectedKey.value = previous }
    },
    openLine: () => {
        const link = links.value.find(candidate => candidate.to === shownTask()) ?? links.value[0]
        const target = cardElement(link?.to)?.getBoundingClientRect()
        if (!canManage || !target || popover.value) return null
        showPopover(link, point({ clientX: target.left, clientY: target.top + target.height / 2 }))

        return () => { popover.value = null }
    },
    openTaskCard: () => {
        const key = shownTask()
        if (!key || openKeys.has(key)) return null
        openKeys.add(key)

        return () => openKeys.delete(key)
    },
}, {
    spots: {
        'checklists.new': () => board.value?.querySelector('[data-tour="checklists.checks"] form') ?? null,
        'checklists.card.check': () => board.value?.querySelector('[data-card^="check:"]') ?? null,
        'checklists.card.list': () => board.value?.querySelector('[data-card^="checklist:"]') ?? null,
        'checklists.card.task': () => cardElement(shownTask()),
        'checklists.menu': () => board.value?.querySelector('[data-card^="checklist:"] [title="Meer"]') ?? null,
    },
})

onMounted(() => window.addEventListener('keydown', closeOnEscape))
onBeforeUnmount(() => window.removeEventListener('keydown', closeOnEscape))
</script>
