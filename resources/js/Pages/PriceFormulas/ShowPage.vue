<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center">
            <Link href="/priceformulas" class="text-sm font-medium text-slate-400">Prijsformules</Link>
            <ChevronRightIcon class="mx-2 size-4 text-gray-400" />
            <span class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ formula.name }}</span>
        </div>
        <div class="flex items-center gap-3 self-start sm:self-auto">
            <AssistantButton />
            <TourButton tour="prijsformule" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-red-600 hover:underline"
                @click="confirmDelete = true">
                Formule verwijderen
            </button>
        </div>
    </div>

    <div class="mb-4 mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3 lg:col-span-2" data-tour="formula.header">
            <EditableTextField v-model="details.name" type="input" label="Naam" :readonly="!canUpdate"
                :error="details.errors.name" @update="() => patchDetails('name')" @revert="details.clearErrors('name')" />
            <EditableTextField v-model="details.description" type="input" label="Omschrijving" placeholder="Geen omschrijving"
                :readonly="!canUpdate" :error="details.errors.description"
                @update="() => patchDetails('description')" @revert="details.clearErrors('description')" />
            <EditableTextField v-model="versionForm.name" type="input" :label="`Naam van versie ${version.number}`"
                placeholder="Geen naam, bijvoorbeeld 2027" :readonly="!canUpdate" :error="versionForm.errors.name"
                @update="patchVersionName" @revert="versionForm.clearErrors('name')" />
        </div>
        <div class="flex flex-col items-start gap-2 lg:items-end" data-tour="formula.version">
            <div class="flex items-center gap-2">
                <BadgeComponent color="blue" :has-dot="false">
                    {{ versionLabel(version.number, version.name, { capital: true }) }}
                </BadgeComponent>
                <span v-if="(dirty || touring) && canUpdate" class="flex items-center gap-2" data-tour="formula.save">
                    <button type="button" :disabled="touring"
                        class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                        @click="discard">
                        Wijzigingen weggooien
                    </button>
                    <button type="button" :disabled="touring || saving || checking"
                        class="rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white shadow-xs hover:opacity-90 disabled:opacity-60"
                        @click="save">
                        {{ version.in_use ? `Opslaan als versie ${version.number + 1}` : 'Opslaan' }}
                    </button>
                </span>
            </div>
            <p class="text-xs text-gray-500 lg:text-right dark:text-slate-400">{{ versionNote }}</p>
        </div>
    </div>

    <p v-if="saveError" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
        Niet opgeslagen: {{ saveError }}
    </p>

    <div class="flex flex-col gap-3 lg:h-[78vh] lg:flex-row">
        <FormulaPalette v-if="canUpdate" :catalog="catalog" @add="addAtCenter" />

        <div ref="canvasRef" data-tour="formula.canvas"
            class="relative h-[70vh] min-w-0 flex-1 overflow-hidden rounded-lg border border-gray-200 bg-slate-50 lg:h-auto dark:border-slate-700 dark:bg-slate-900"
            @dragover.prevent @drop="onDrop">
            <VueFlow :id="FLOW_ID" :nodes="initialNodes" :edges="initialEdges" :node-types="nodeTypes"
                :is-valid-connection="isValidConnection" :nodes-draggable="canUpdate" :nodes-connectable="canUpdate"
                :delete-key-code="canUpdate ? ['Backspace', 'Delete'] : null" :min-zoom="0.2"
                @connect="connect" @nodes-initialized="openReadable">
                <Background pattern-color="#cbd5e1" :gap="20" />
                <Controls :show-interactive="false" />
                <MiniMap pannable zoomable />
            </VueFlow>
            <div data-tour="formula.totals"
                class="pointer-events-none absolute left-3 top-3 rounded-md bg-white/90 px-3 py-2 text-xs shadow-sm dark:bg-slate-800/90">
                <p class="font-bold text-gray-900 dark:text-white">
                    Per periode: {{ results?.periodic == null ? '—' : nlCurrency(results.periodic) }}
                </p>
                <p class="text-gray-600 dark:text-slate-300">
                    Eenmalig: {{ results?.one_time == null ? '—' : nlCurrency(results.one_time) }}
                </p>
                <p class="text-gray-400">{{ onContract ? 'Op het gekozen contract' : 'Op de voorbeeldklant' }}</p>
            </div>
        </div>

        <aside class="shrink-0 overflow-y-auto rounded-lg bg-white p-4 shadow-sm lg:w-80 dark:bg-slate-800" data-tour="formula.panel">
            <SegmentedToggle v-model="panel" :options="panels" class="mb-4" data-tour="formula.panel.tabs" />
            <template v-if="panel === 'block'">
                <NodeSettings v-if="selectedNode" :key="selectedNode.id" :node="selectedNode"
                    :definition="definitions[selectedNode.type]" :product-types="productTypes" :brands="brands"
                    :attributes="attributes" :product-options="productOptions" :products-use-ajax="productsUseAjax"
                    :readonly="!canUpdate" @remove="removeNodes([selectedNode.id])"
                    @change="data => updateNodeData(selectedNode.id, data, { replace: true })" />
                <p v-else class="text-sm text-gray-500 dark:text-slate-400">
                    Klik op een blok om het in te stellen. Trek een lijn van een uitgang (rechts) naar een ingang (links):
                    <span class="font-medium text-blue-600 dark:text-blue-400">blauw</span> zijn machines,
                    <span class="font-medium text-slate-500">grijs</span> zijn getallen. Gaat een blauwe lijn naar een grijze
                    ingang, dan worden de machines geteld. Een lijn of blok weghalen doe je met Backspace.
                </p>
            </template>
            <TestBench v-else v-model="bench" :input-blocks="inputBlocks" :test-contracts="testContracts"
                :interval-options="intervalOptions" :picker-props="pickerProps" :results="results" :error="previewError" />
        </aside>
    </div>

    <BoxComponent v-if="seesContracts" class="mt-4" data-tour="formula.contracts">
        <SectionHeader :icon="ClipboardDocumentCheckIcon" title="Contracten op deze formule" chapter="contracts"
            subtitle="Een contract houdt zijn versie tot je het bijwerkt; hier zie je wat de prijs met de nieuwste versie wordt." />
        <Deferred data="contracts">
            <template #fallback>
                <p class="text-sm text-gray-500 dark:text-slate-400">De prijzen van de contracten worden uitgerekend…</p>
            </template>
            <ContractsOnFormula :formula-id="formula.id" :contracts="contracts" :latest-version="version.number"
                :can-upgrade="canUpgrade" :blocked="unsaved" />
        </Deferred>
        <p v-if="templates.length" class="mt-4 text-sm text-gray-500 dark:text-slate-400" data-tour="formula.templates">
            Gebruikt in de sjablonen
            <template v-for="(template, index) in templates" :key="template.id">
                <Link :href="`/maintenancecontracttemplates/${template.id}`" class="underline">{{ template.name }}</Link>{{
                    index < templates.length - 1 ? ', ' : '' }}
            </template>.
        </p>
    </BoxComponent>

    <BoxComponent class="mt-4">
        <SectionHeader :icon="TimelineIcon" title="Tijdlijn"
            subtitle="Wie wat veranderde aan deze formule en welke sjablonen hem kozen of loslieten, op volgorde." chapter="timeline" />
        <TimelineComponent v-if="activities.length" :activities="activities" subject-type="App\Models\PriceFormula" />
        <p v-else class="text-sm text-gray-400 dark:text-slate-500">Nog niets gebeurd.</p>
    </BoxComponent>

    <ModalDialog v-model:open="confirmDelete" title="Formule verwijderen" max-width-class="sm:max-w-md">
        <p class="text-sm text-gray-600 dark:text-slate-300">
            {{ formula.name }} en al zijn versies verdwijnen. Sjablonen met deze formule krijgen weer een vaste prijs.
        </p>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button"
                    class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 dark:text-white dark:ring-slate-600"
                    @click="confirmDelete = false">
                    Annuleren
                </button>
                <button type="button" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:opacity-90"
                    @click="destroy">
                    Verwijderen
                </button>
            </div>
        </template>
    </ModalDialog>

    <ModalDialog v-model:open="newVersion.open" :title="`Opslaan als versie ${newVersion.impact?.number ?? ''}`"
        max-width-class="sm:max-w-lg">
        <div v-if="newVersion.impact" class="space-y-4 text-sm text-gray-600 dark:text-slate-300">
            <TextInput id="new-version-name" v-model="newVersion.name" label="Naam van de versie" placeholder="Bijvoorbeeld 2027"
                :has-error="Boolean(newVersion.error)" :error-message="newVersion.error" />
            <p v-if="!running.count">
                Er staat geen lopend contract op deze formule. Nieuwe contracten krijgen versie {{ newVersion.impact.number }}.
            </p>
            <template v-else-if="newVersion.impact.can_move_contracts">
                <p>
                    {{ running.subject }} op deze formule {{ running.one ? 'kost' : 'kosten samen' }}
                    {{ nlCurrency(newVersion.impact.now) }} per periode. Gaan ze mee naar versie {{ newVersion.impact.number }}?
                </p>
                <ul class="space-y-2">
                    <li>
                        <span class="font-semibold text-gray-900 dark:text-white">Laten staan:</span>
                        {{ running.one ? 'het houdt zijn versie en zijn prijs' : 'ze houden hun versie en hun prijs' }} tot je bijwerkt.
                    </li>
                    <li>
                        <span class="font-semibold text-gray-900 dark:text-white">Meenemen:</span>
                        de prijs wordt opnieuw berekend{{ running.one ? '' : ', samen' }}
                        <span class="font-semibold" :class="newVersion.impact.then > newVersion.impact.now
                            ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-400'">
                            {{ nlCurrency(newVersion.impact.then) }}
                        </span>
                        per periode. De nieuwe prijs komt op de tijdlijn van {{ running.one ? 'het' : 'elk' }} contract.
                    </li>
                </ul>
            </template>
            <p v-else>
                {{ running.subject }} {{ running.one ? 'blijft op zijn versie en houdt zijn' : 'blijven op hun versie en houden hun' }}
                prijs. Bijwerken kan iemand die contracten en hun prijzen mag wijzigen.
            </p>
        </div>
        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button"
                    class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                    @click="newVersion.open = false">
                    Annuleren
                </button>
                <template v-if="running.count && newVersion.impact.can_move_contracts">
                    <button type="button" :disabled="saving"
                        class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                        @click="saveAsNewVersion(false)">
                        Laten staan
                    </button>
                    <button type="button" :disabled="saving"
                        class="rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60"
                        @click="saveAsNewVersion(true)">
                        Meenemen naar versie {{ newVersion.impact.number }}
                    </button>
                </template>
                <button v-else type="button" :disabled="saving"
                    class="rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60"
                    @click="saveAsNewVersion(false)">
                    Opslaan
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, markRaw, onBeforeUnmount, provide, reactive, ref, watch } from 'vue'
import { Deferred, Link, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import { cloneDeep, debounce } from 'lodash'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/minimap/dist/style.css'
import { ChevronRightIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'
import { TimelineIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import FormulaNode from '@/Components/PriceFormulas/FormulaNode.vue'
import FormulaPalette from '@/Components/PriceFormulas/FormulaPalette.vue'
import NodeSettings from '@/Components/PriceFormulas/NodeSettings.vue'
import TestBench from '@/Components/PriceFormulas/TestBench.vue'
import ContractsOnFormula from '@/Components/PriceFormulas/ContractsOnFormula.vue'
import TourButton from '@/Components/Tour/TourButton.vue'
import AssistantButton from '@/Components/Assistant/AssistantButton.vue'
import { usePriceFormulaTour } from '@/Components/PriceFormulas/usePriceFormulaTour'
import { tourSample } from '@/Components/PriceFormulas/tourExamples'
import { useLeaveGuard } from '@/Composables/useLeaveGuard'
import {
    DRAG_TYPE, EDITOR_KEY, PICKED_MODELS_KEY, edgeLook, newNodeId, serializeGraph, settingOf, settingsOf, versionLabel,
} from '@/Components/PriceFormulas/formulaEditor'
import { hasPermission, nlCurrency, nlDate } from '@/Utilities/Utilities'

/**
 * The price formula editor. Blocks on a canvas, wired output to input; every
 * change is worked out by the server straight away over the trial customer or a
 * real contract, and the values show on the blocks themselves.
 */
const props = defineProps({
    formula: { type: Object, required: true },
    version: { type: Object, required: true },
    catalog: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    attributes: { type: Array, default: () => [] },
    productOptions: { type: Array, default: () => [] },
    productsUseAjax: { type: Boolean, default: false },
    intervalOptions: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    seesContracts: { type: Boolean, default: false },
    contracts: { type: Array, default: () => [] },
    testContracts: { type: Array, default: () => [] },
    testContractId: { type: Number, default: null },
    tourTypes: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
})

const FLOW_ID = 'price-formula-' + props.formula.id

const canUpdate = computed(() => hasPermission('priceformula.update'))
const canDelete = computed(() => hasPermission('priceformula.delete'))
const canUpgrade = computed(() => canUpdate.value && hasPermission('maintenancecontract.update'))

const definitions = Object.fromEntries(props.catalog.types.map(type => [type.type, type]))
const nodeTypes = Object.fromEntries(props.catalog.types.map(type => [type.type, markRaw(FormulaNode)]))

const kindOf = (nodeType, handle, side) =>
    definitions[nodeType]?.[side === 'source' ? 'outputs' : 'inputs'].find(h => h.id === handle)?.kind

const nodesOf = graph => graph.nodes.map(node => ({ ...node, data: cloneDeep(settingsOf(node)) }))
const edgesOf = graph => graph.edges.map(edge => ({
    ...edge,
    ...edgeLook(kindOf(graph.nodes.find(node => node.id === edge.source)?.type, edge.sourceHandle, 'source')),
}))

const initialNodes = nodesOf(props.version.graph)
const initialEdges = edgesOf(props.version.graph)

const flow = useVueFlow(FLOW_ID)
const {
    nodes, edges, addNodes, addEdges, removeNodes, findNode, screenToFlowCoordinate, getSelectedNodes,
    addSelectedNodes, removeSelectedNodes, setNodes, setEdges, updateNodeData, fitView, getViewport, setViewport,
} = flow

const canvasRef = ref(null)
const panel = ref(props.testContractId ? 'test' : 'block')
const panels = [
    { value: 'block', label: 'Blok' },
    { value: 'test', label: 'Proberen' },
]

const bench = reactive({
    mode: props.testContractId ? 'contract' : 'sample',
    contract_id: props.testContractId,
    sample: tourSample(props.tourTypes),
    price_interval: 'Jaarlijks',
    frequency: 'Jaarlijks',
    inputs: {},
})

const { touring, heldGraph, reshaping, clearTimers } = usePriceFormulaTour({
    flow,
    canvasRef,
    panel,
    bench,
    tourTypes: () => props.tourTypes,
    testContracts: () => props.testContracts,
    connect,
    nodesOf,
    edgesOf,
})

const savedGraph = ref(JSON.stringify(serializeGraph(initialNodes, initialEdges)))
const currentGraph = computed(() => serializeGraph(nodes.value, edges.value))

/** Changed since the last save; never while the rondleiding has its examples on the canvas. */
const dirty = computed(() => canUpdate.value && !touring.value && nodes.value.length > 0
    && JSON.stringify(currentGraph.value) !== savedGraph.value)

/** The person's own changes not saved yet, also while the rondleiding has them set aside. */
const unsaved = computed(() => (touring.value
    ? canUpdate.value && JSON.stringify(heldGraph.value) !== savedGraph.value
    : dirty.value))

const versionNote = computed(() => {
    const { number, saved_at: savedAt, saved_by: savedBy, in_use: inUse } = props.version
    const saved = savedAt ? `Laatst opgeslagen ${nlDate(savedAt)}${savedBy ? ` door ${savedBy}` : ''}. ` : ''

    return saved + (inUse
        ? `Er staan contracten op versie ${number}. Opslaan maakt versie ${number + 1}; je kiest dan of de lopende contracten meegaan.`
        : `Er staat nog geen contract op versie ${number}, dus opslaan werkt deze versie bij.`)
})

const pickedModels = reactive([])

const names = computed(() => ({
    productTypes: Object.fromEntries(props.productTypes.map(type => [type.id, type.name])),
    brands: Object.fromEntries(props.brands.map(brand => [brand.id, brand.name])),
    attributes: Object.fromEntries(props.attributes.map(attribute => [attribute.id, attribute.name])),
    attributeUnits: Object.fromEntries(props.attributes.map(attribute => [attribute.id, attribute.unit ?? ''])),
    products: Object.fromEntries([...props.productOptions, ...pickedModels].map(product => [product.id, product.name])),
}))

const results = ref(null)
const previewError = ref(null)

provide(EDITOR_KEY, { definitions, results, names })
provide(PICKED_MODELS_KEY, pickedModels)

/**
 * Whether a wire from a's output may go to b's input: the same kind, or
 * machines into a number, which counts them; and no way back from b to a.
 */
function isValidConnection(connection) {
    const source = findNode(connection.source)
    const target = findNode(connection.target)
    if (!source || !target || source.id === target.id) return false

    const out = kindOf(source.type, connection.sourceHandle, 'source')
    const into = kindOf(target.type, connection.targetHandle, 'target')
    if (!props.catalog.connects[out]?.includes(into)) return false

    return !leadsTo(target.id, source.id)
}

function leadsTo(from, to, seen = new Set()) {
    if (from === to) return true
    if (seen.has(from)) return false
    seen.add(from)

    return edges.value.filter(edge => edge.source === from).some(edge => leadsTo(edge.target, to, seen))
}

function connect(connection) {
    const exists = edges.value.some(edge => ['source', 'sourceHandle', 'target', 'targetHandle']
        .every(key => edge[key] === connection[key]))
    if (exists || !isValidConnection(connection)) return

    addEdges([{
        ...connection,
        id: newNodeId('e'),
        ...edgeLook(kindOf(findNode(connection.source).type, connection.sourceHandle, 'source')),
    }])
}

function addBlock(type, position) {
    const id = newNodeId(type)
    const data = Object.fromEntries(definitions[type].fields.map(field => [field.key, cloneDeep(field.default ?? null)]))
    addNodes([{ id, type, position, data }])
    panel.value = 'block'
    setTimeout(() => {
        removeSelectedNodes(getSelectedNodes.value)
        const node = findNode(id)
        if (node) addSelectedNodes([node])
    })
}

function onDrop(event) {
    const type = event.dataTransfer?.getData(DRAG_TYPE)
    if (type && definitions[type]) addBlock(type, screenToFlowCoordinate({ x: event.clientX, y: event.clientY }))
}

/** Half a block's width (w-64), so the block's middle lands in the middle of the view, not its left edge. */
const HALF_BLOCK_WIDTH = 128

/** Closer than this to a block already there, a new block would cover it. */
const BLOCK_CLEARANCE = { x: 270, y: 150 }

/** How far a new block moves down past each block in its way. */
const BLOCK_STEP_DOWN = 160

/** A clicked block goes in the middle of what is in view, below whatever already stands there. */
function addAtCenter(type) {
    const rect = canvasRef.value.getBoundingClientRect()
    const position = screenToFlowCoordinate({ x: rect.left + rect.width / 2 - HALF_BLOCK_WIDTH, y: rect.top + rect.height / 3 })
    const taken = point => nodes.value.some(node => Math.abs(node.position.x - point.x) < BLOCK_CLEARANCE.x
        && Math.abs(node.position.y - point.y) < BLOCK_CLEARANCE.y)

    while (taken(position)) position.y += BLOCK_STEP_DOWN

    addBlock(type, position)
}

/** The smallest zoom at which the text on a block can still be read. */
const READABLE_ZOOM = 0.65

/** Room between the canvas's left edge and the first block when a wide formula opens at its left end. */
const READABLE_LEFT_MARGIN = 24

let opened = false

/**
 * Opens, once the blocks have been measured, on the whole formula when it fits
 * at a size that can still be read; a wider one opens readable at its left end,
 * where the machines come in.
 */
async function openReadable() {
    if (opened || !nodes.value.length) return
    opened = true

    await fitView({ padding: 0.1, maxZoom: 1 })
    const viewport = getViewport()
    if (viewport.zoom >= READABLE_ZOOM) return

    const rect = canvasRef.value.getBoundingClientRect()
    const centreY = (rect.height / 2 - viewport.y) / viewport.zoom
    const left = Math.min(...nodes.value.map(node => node.position.x))

    setViewport({
        x: READABLE_LEFT_MARGIN - left * READABLE_ZOOM,
        y: rect.height / 2 - centreY * READABLE_ZOOM,
        zoom: READABLE_ZOOM,
    })
}

const selectedNode = computed(() => (getSelectedNodes.value.length === 1 ? getSelectedNodes.value[0] : null))

/**
 * Choosing a block opens its settings; choosing none opens the try-out. Not
 * while the rondleiding takes the canvas apart and puts it back, which clears
 * and restores the selection on the way and sets the panel itself. Run at once
 * rather than before the next render, so the rondleiding is still at work when
 * this is asked.
 */
watch(() => selectedNode.value?.id, id => {
    if (!reshaping()) panel.value = id ? 'block' : 'test'
}, { flush: 'sync' })

const inputBlocks = computed(() => nodes.value
    .filter(node => node.type === 'input')
    .map(node => ({
        id: node.id,
        name: settingOf(node, definitions.input, 'name'),
        default: Number(settingOf(node, definitions.input, 'default')),
    })))

const pickerProps = computed(() => ({
    productTypes: props.productTypes,
    productOptions: props.productOptions,
    productsUseAjax: props.productsUseAjax,
}))

const onContract = computed(() => bench.mode === 'contract' && Boolean(bench.contract_id))

/**
 * Only the answer to the latest question counts: every change moves the count
 * on at once, so an answer about an earlier graph that arrives while the next
 * question still waits is dropped too.
 */
let previewSequence = 0

const requestPreview = debounce(async () => {
    const sequence = previewSequence
    const payload = {
        graph: currentGraph.value,
        inputs: bench.inputs,
        ...(onContract.value
            ? { maintenance_contract_id: bench.contract_id }
            : { sample: bench.sample, price_interval: bench.price_interval, frequency: bench.frequency }),
    }

    try {
        const { data } = await axios.post('/priceformulas/preview', payload)
        if (sequence !== previewSequence) return
        results.value = data
        previewError.value = null
    } catch (error) {
        if (sequence !== previewSequence) return
        const errors = error.response?.data?.errors
        results.value = null
        previewError.value = errors ? Object.values(errors).flat()[0] : 'Uitrekenen lukt nu niet. Probeer het zo nog eens.'
    }
}, 300)

/** Moving a block changes no price, so only the blocks, their settings and the wires are watched. */
const priceShape = computed(() => JSON.stringify(serializeGraph(nodes.value, edges.value, { layout: false })))

watch([priceShape, () => JSON.stringify(bench)], () => {
    previewSequence++
    requestPreview()
}, { immediate: true })

const saving = ref(false)
const checking = ref(false)
const saveError = ref(null)

/**
 * The version the canvas is built on. A save names it, so the server refuses
 * when someone else saved after it; that stays so until this canvas is saved
 * or thrown away, never by just pressing Opslaan again.
 */
const baseVersion = ref(props.version.number)

/**
 * A save that makes a new version asks first: what the version is called, and
 * whether the running contracts come along. The server says whether it would be
 * a new version and what those contracts cost now and then; the money only
 * comes back for whoever may move contracts along. What is saved is the graph
 * the question was about, so a change made while it was asked stays unsaved.
 */
const newVersion = reactive({ open: false, impact: null, graph: null, name: '', error: null })

const running = computed(() => {
    const count = newVersion.impact?.running ?? 0

    return { count, one: count === 1, subject: count === 1 ? 'Eén lopend contract' : `${count} lopende contracten` }
})

async function save() {
    if (touring.value) return
    saveError.value = null
    const graph = currentGraph.value

    if (!props.version.in_use) {
        store(graph)
        return
    }

    checking.value = true
    try {
        const { data } = await axios.post(`/priceformulas/${props.formula.id}/impact`, { graph })
        if (!data.new_version) {
            store(graph)
            return
        }
        Object.assign(newVersion, { open: true, impact: data, graph, name: '', error: null })
    } catch (error) {
        const errors = error.response?.data?.errors
        saveError.value = errors
            ? Object.values(errors).flat()[0]
            : error.response?.data?.message ?? 'Opslaan lukt nu niet. Probeer het zo nog eens.'
    } finally {
        checking.value = false
    }
}

function saveAsNewVersion(moveContracts) {
    newVersion.error = null
    store(newVersion.graph, { version_name: newVersion.name || null, move_contracts: moveContracts })
}

/**
 * Says which version the changes were made on. Saved by someone else in the
 * meantime, the server refuses rather than overwrite that version: a refusal
 * comes back as the page with an error flashed, not as a field error, so the
 * canvas only counts as saved without one.
 */
function store(graph, choices = {}) {
    saving.value = true

    router.put(`/priceformulas/${props.formula.id}/graph`, { graph, version: baseVersion.value, ...choices }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: page => {
            newVersion.open = false
            const refused = page.props.flash?.error
            if (refused) {
                saveError.value = refused
                return
            }
            savedGraph.value = JSON.stringify(graph)
            baseVersion.value = page.props.version.number
        },
        onError: errors => {
            if (errors.version_name) {
                newVersion.error = errors.version_name
                return
            }
            newVersion.open = false
            saveError.value = errors.graph ?? Object.values(errors)[0]
        },
        onFinish: () => { saving.value = false },
    })
}

/** Back to what was saved last. */
function discard() {
    if (touring.value) return
    setNodes(nodesOf(props.version.graph))
    setEdges(edgesOf(props.version.graph))
    savedGraph.value = JSON.stringify(serializeGraph(nodesOf(props.version.graph), edgesOf(props.version.graph)))
    baseVersion.value = props.version.number
    saveError.value = null
}

const details = useForm({ name: props.formula.name, description: props.formula.description ?? '' })

const versionForm = useForm({ name: props.version.name ?? '' })

watch(() => [props.version.number, props.version.name], () => {
    versionForm.defaults({ name: props.version.name ?? '' })
    versionForm.reset()
})

function patchVersionName() {
    versionForm.transform(data => ({ version: props.version.number, name: data.name || null }))
        .patch(`/priceformulas/${props.formula.id}/version`, { preserveScroll: true, preserveState: true })
}

function patchDetails(field) {
    details.transform(data => ({ [field]: data[field] }))
        .patch(`/priceformulas/${props.formula.id}`, { preserveScroll: true, preserveState: true })
}

const confirmDelete = ref(false)

function destroy() {
    confirmDelete.value = false
    router.delete(`/priceformulas/${props.formula.id}`)
}

useLeaveGuard(() => unsaved.value, 'Je wijzigingen aan de formule zijn nog niet opgeslagen. Toch weggaan?')

onBeforeUnmount(() => {
    requestPreview.cancel()
    clearTimers()
})
</script>
