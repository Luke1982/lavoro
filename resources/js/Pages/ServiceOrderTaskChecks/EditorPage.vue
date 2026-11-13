<template>
    <IndexHeaderComponent title="Checklists voor werkbontaken"
        subtitle="Stel per werkbontaak in welke checks de monteur afvinkt." :show-search="false" />

    <BoxComponent class="md:hidden">
        <p class="text-sm text-gray-600 dark:text-slate-300">
            Open deze pagina op een groter scherm om checklists te beheren.
        </p>
    </BoxComponent>

    <BoxComponent class="hidden md:block">
        <div ref="board" class="relative grid h-[calc(100vh-15rem)] min-h-[28rem] grid-cols-3 gap-x-20"
            v-on="handlers" @click="popover = null">
            <EditorLines :links="links" :highlighted="connected" @line-click="openPopover" />

            <EditorColumn v-model:search="search.check" v-model:draft="drafts.check" title="Checks"
                :count="checks.length" :add-label="canManage ? '+ Nieuwe check' : null" search-placeholder="Zoek een check..."
                :error="errors.check" @add="title => create('check', title)">
                <EditorCard v-for="check in visible.check" :key="'check:' + check.id" v-bind="card('check:' + check.id)"
                    kind="check" :title="check.title" :editable="canManage" :draggable="canManage"
                    @rename="title => rename('check', check.id, title)" @delete="deleting = 'check:' + check.id" />
                <p v-if="!visible.check.length" class="text-sm text-gray-400">Geen checks gevonden.</p>
            </EditorColumn>

            <EditorColumn v-model:search="search.checklist" v-model:draft="drafts.checklist" title="Checklists"
                :count="checklists.length" :add-label="canManage ? '+ Nieuwe checklist' : null" search-placeholder="Zoek een checklist..."
                :error="errors.checklist" @add="title => create('checklist', title)">
                <EditorCard v-for="list in visible.checklist" :key="'checklist:' + list.id"
                    v-bind="card('checklist:' + list.id)" kind="checklist" :title="list.title" :editable="canManage"
                    openable :draggable="canManage" @rename="title => rename('checklist', list.id, title)"
                    @delete="deleting = 'checklist:' + list.id">
                    <CardContents :items="contents.checklist(list)" :readonly="!canManage"
                        @reorder="ids => reorder('check', ids)" @set-required="(item, value) => setRequired('check', item.linkId, value)" />
                </EditorCard>
                <p v-if="!visible.checklist.length" class="text-sm text-gray-400">Geen checklists gevonden.</p>
            </EditorColumn>

            <EditorColumn v-model:search="search.task" title="Taken" :count="tasks.length"
                search-placeholder="Zoek een taak...">
                <EditorCard v-for="task in visible.task" :key="'task:' + task.id" v-bind="card('task:' + task.id)"
                    kind="task" :title="task.title" openable>
                    <div class="space-y-3">
                        <CardContents heading="Checklists" :items="contents.taskChecklists(task)" :readonly="!canManage" @reorder="ids => reorder('checklist', ids)"
                            @set-required="(item, value) => setRequired('checklist', item.linkId, value)" />
                        <CardContents heading="Losse checks" :items="contents.taskChecks(task)" :readonly="!canManage"
                            @reorder="ids => reorder('check', ids)"
                            @set-required="(item, value) => setRequired('check', item.linkId, value)" />
                    </div>
                </EditorCard>
                <p v-if="!visible.task.length" class="text-sm text-gray-400">
                    Geen taken gevonden. Taken maak je aan onder Werkbonnen → Taken.
                </p>
            </EditorColumn>

            <LinkPopover v-if="popover" :link="popover.link" :label="popover.label" :position="popover.position"
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
                <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="deleting = null">
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
const { links, titleOf, usageSentence, meta, connectedTo, contents } = useChecklistGraph(props)

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
    check: props.checks.filter(check => matches(check.title, search.check)),
    checklist: props.checklists.filter(list => matches(list.title, search.checklist)),
    task: props.tasks.filter(task => matches(task.title, search.task)),
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

/** Every change saves through here: the page keeps its scroll, its selection and its open cards. */
function send(method, url, data = {}, options = {}) {
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
    canStart: key => kindOf(key) in TARGETS,
    canLink: (from, to) => (TARGETS[kindOf(from)] ?? []).includes(kindOf(to))
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
    if (!canManage) return

    const { x, y } = point(event)

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

onMounted(() => window.addEventListener('keydown', closeOnEscape))
onBeforeUnmount(() => window.removeEventListener('keydown', closeOnEscape))
</script>
