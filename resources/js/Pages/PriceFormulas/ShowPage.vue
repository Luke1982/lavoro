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
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:col-span-2" data-tour="formula.header">
            <EditableTextField v-model="details.name" type="input" label="Naam" :readonly="!canUpdate"
                :error="details.errors.name" @update="() => patchDetails('name')" @revert="details.clearErrors('name')" />
            <EditableTextField v-model="details.description" type="input" label="Omschrijving" placeholder="Geen omschrijving"
                :readonly="!canUpdate" :error="details.errors.description"
                @update="() => patchDetails('description')" @revert="details.clearErrors('description')" />
        </div>
        <div class="flex flex-col items-start gap-2 lg:items-end" data-tour="formula.version">
            <div class="flex items-center gap-2">
                <BadgeComponent color="blue" :has-dot="false">Versie {{ version.number }}</BadgeComponent>
                <span v-if="(dirty || touring) && canUpdate" class="flex items-center gap-2" data-tour="formula.save">
                    <button type="button" :disabled="touring"
                        class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                        @click="discard">
                        Wijzigingen weggooien
                    </button>
                    <button type="button" :disabled="touring || saving"
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
                    Per periode: {{ results?.periodic == null ? '—' : formatResult(results.periodic) }}
                </p>
                <p class="text-gray-600 dark:text-slate-300">
                    Eenmalig: {{ results?.one_time == null ? '—' : formatResult(results.one_time) }}
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
                    Klik op een blok om het in te stellen. Trek een lijn van een uitgang (rechts) naar een ingang (links) van dezelfde kleur:
                    <span class="font-medium text-blue-600 dark:text-blue-400">blauw</span> zijn machines,
                    <span class="font-medium text-slate-500">grijs</span> zijn getallen. Een lijn of blok weghalen doe je met
                    Backspace.
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
import BoxComponent from '@/Components/BoxComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import FormulaNode from '@/Components/PriceFormulas/FormulaNode.vue'
import FormulaPalette from '@/Components/PriceFormulas/FormulaPalette.vue'
import NodeSettings from '@/Components/PriceFormulas/NodeSettings.vue'
import TestBench from '@/Components/PriceFormulas/TestBench.vue'
import ContractsOnFormula from '@/Components/PriceFormulas/ContractsOnFormula.vue'
import TourButton from '@/Components/Tour/TourButton.vue'
import AssistantButton from '@/Components/Assistant/AssistantButton.vue'
import { usePriceFormulaTour } from '@/Components/PriceFormulas/usePriceFormulaTour'
import { useLeaveGuard } from '@/Composables/useLeaveGuard'
import {
    DRAG_TYPE, EDITOR_KEY, PICKED_MODELS_KEY, edgeLook, formatResult, newNodeId, serializeGraph, settingsOf,
} from '@/Components/PriceFormulas/formulaEditor'
import { hasPermission, nlDate } from '@/Utilities/Utilities'

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
    sample: [{ quantity: 3, product_type_id: null, product_id: null, address: 1 }],
    price_interval: 'Jaarlijks',
    frequency: 'Jaarlijks',
    inputs: {},
})

const { touring, heldGraph, clearTimers } = usePriceFormulaTour({
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
        ? `Er staan contracten op versie ${number}. Opslaan maakt versie ${number + 1}; die contracten houden hun prijs tot je ze bijwerkt.`
        : `Er staat nog geen contract op versie ${number}, dus opslaan werkt deze versie bij.`)
})

const pickedModels = reactive([])

const names = computed(() => ({
    productTypes: Object.fromEntries(props.productTypes.map(type => [type.id, type.name])),
    brands: Object.fromEntries(props.brands.map(brand => [brand.id, brand.name])),
    attributes: Object.fromEntries(props.attributes.map(attribute => [attribute.id, attribute.name])),
    products: Object.fromEntries([...props.productOptions, ...pickedModels].map(product => [product.id, product.name])),
}))

const results = ref(null)
const previewError = ref(null)

provide(EDITOR_KEY, { definitions, results, names })
provide(PICKED_MODELS_KEY, pickedModels)

/** Whether a wire from a's output may go to b's input: same kind, and no way back from b to a. */
function isValidConnection(connection) {
    const source = findNode(connection.source)
    const target = findNode(connection.target)
    if (!source || !target || source.id === target.id) return false

    const out = kindOf(source.type, connection.sourceHandle, 'source')
    const into = kindOf(target.type, connection.targetHandle, 'target')
    if (!out || out !== into) return false

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

/** A clicked block goes in the middle of what is in view, below whatever already stands there. */
function addAtCenter(type) {
    const rect = canvasRef.value.getBoundingClientRect()
    const position = screenToFlowCoordinate({ x: rect.left + rect.width / 2 - 128, y: rect.top + rect.height / 3 })
    const taken = point => nodes.value.some(node =>
        Math.abs(node.position.x - point.x) < 270 && Math.abs(node.position.y - point.y) < 150)

    while (taken(position)) position.y += 160

    addBlock(type, position)
}

const READABLE_ZOOM = 0.65
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

    setViewport({ x: 24 - left * READABLE_ZOOM, y: rect.height / 2 - centreY * READABLE_ZOOM, zoom: READABLE_ZOOM })
}

const selectedNode = computed(() => (getSelectedNodes.value.length === 1 ? getSelectedNodes.value[0] : null))

watch(() => selectedNode.value?.id, id => { panel.value = id ? 'block' : 'test' })

const inputBlocks = computed(() => nodes.value
    .filter(node => node.type === 'input')
    .map(node => ({ id: node.id, name: node.data?.name || 'Invoer', default: Number(node.data?.default ?? 0) })))

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
const saveError = ref(null)

function save() {
    if (touring.value) return
    saving.value = true
    saveError.value = null
    const graph = currentGraph.value

    router.put(`/priceformulas/${props.formula.id}/graph`, { graph }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { savedGraph.value = JSON.stringify(graph) },
        onError: errors => { saveError.value = errors.graph ?? Object.values(errors)[0] },
        onFinish: () => { saving.value = false },
    })
}

/** Back to what was saved last. */
function discard() {
    if (touring.value) return
    setNodes(nodesOf(props.version.graph))
    setEdges(edgesOf(props.version.graph))
    saveError.value = null
}

const details = useForm({ name: props.formula.name, description: props.formula.description ?? '' })

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
