import { nextTick, ref } from 'vue'
import { cloneDeep } from 'lodash'
import { useTour } from '@/Composables/useTour'
import { serializeGraph } from './formulaEditor'
import { tourSample, tourStage } from './tourExamples'

/**
 * What the price formula editor offers its rondleiding: example formulas put on
 * the canvas one stage at a time, blocks lit up as if clicked, and the places on
 * the canvas the steps point at.
 *
 * Every chapter keeps the person's own formula, view and try-out aside and puts
 * them back when it ends. While the tour runs, `touring` is true and `heldGraph`
 * is the person's own formula: the page judges unsaved work by that one, so an
 * example can never be saved, and leaving still warns about real changes.
 *
 * The ghost of a wire being drawn takes about this long to reach its end.
 */
const WIRE_LANDS_AFTER = 3600

export function usePriceFormulaTour({ flow, canvasRef, panel, bench, tourTypes, testContracts, connect, nodesOf, edgesOf }) {
    const touring = ref(false)
    const heldGraph = ref(null)
    let timers = []

    const later = (milliseconds, fn) => timers.push(setTimeout(fn, milliseconds))

    function clearTimers() {
        timers.forEach(clearTimeout)
        timers = []
    }

    function fitAll() {
        later(60, () => flow.fitView({ padding: 0.12, maxZoom: 1 }))
    }

    function keepCanvas() {
        const kept = {
            graph: cloneDeep(serializeGraph(flow.nodes.value, flow.edges.value)),
            viewport: flow.getViewport(),
            bench: cloneDeep({ ...bench }),
            panel: panel.value,
        }
        heldGraph.value = kept.graph
        touring.value = true

        return async () => {
            clearTimers()
            flow.removeSelectedNodes(flow.getSelectedNodes.value)
            flow.setNodes(nodesOf(kept.graph))
            flow.setEdges(edgesOf(kept.graph))
            Object.assign(bench, kept.bench)
            panel.value = kept.panel
            await nextTick()
            flow.setViewport(kept.viewport)
            touring.value = false
            heldGraph.value = null
        }
    }

    /**
     * Puts one example formula on the canvas, tried on the example customer. A
     * block that was lit up stays lit up when it is in the next stage too.
     */
    function showStage(name) {
        const selected = flow.getSelectedNodes.value.map(node => node.id)
        const graph = tourStage(name, tourTypes())
        flow.removeSelectedNodes(flow.getSelectedNodes.value)
        flow.setNodes(nodesOf(graph))
        flow.setEdges(edgesOf(graph))
        Object.assign(bench, {
            mode: 'sample',
            sample: tourSample(tourTypes()),
            price_interval: 'Jaarlijks',
            frequency: 'Jaarlijks',
            inputs: {},
        })
        later(0, () => {
            const stillThere = selected.map(id => flow.findNode(id)).filter(Boolean)
            if (stillThere.length) flow.addSelectedNodes(stillThere)
        })
        fitAll()

        return null
    }

    function selectExample(id) {
        flow.removeSelectedNodes(flow.getSelectedNodes.value)
        const node = flow.findNode('tour-' + id)
        if (node) flow.addSelectedNodes([node])
        panel.value = 'block'

        return () => flow.removeSelectedNodes(flow.getSelectedNodes.value)
    }

    /** Zooms in on part of the example; going back a step shows all of it again. */
    function focusExamples(...ids) {
        later(60, () => flow.fitView({ nodes: ids.map(id => 'tour-' + id), padding: 0.35, maxZoom: 1 }))

        return fitAll
    }

    /** Draws the wire the person watches being drawn, once the hand gets there; going back takes it away. */
    function wireLater() {
        const wire = { source: 'tour-machines', sourceHandle: 'machines', target: 'tour-count', targetHandle: 'machines' }
        later(WIRE_LANDS_AFTER, () => connect(wire))

        return () => {
            clearTimers()
            const drawn = flow.edges.value.filter(edge => edge.source === wire.source && edge.target === wire.target)
            flow.removeEdges(drawn.map(edge => edge.id))
        }
    }

    function showTrialPanel() {
        const before = panel.value
        flow.removeSelectedNodes(flow.getSelectedNodes.value)
        panel.value = 'test'

        return () => { panel.value = before }
    }

    /** The try-out on a real contract, when there is one to try on. */
    function tryOnContract() {
        const before = { mode: bench.mode, contract_id: bench.contract_id }
        const contracts = testContracts()
        panel.value = 'test'
        if (contracts.length) {
            bench.mode = 'contract'
            bench.contract_id = bench.contract_id ?? contracts[0].id
        }

        return () => Object.assign(bench, before)
    }

    function fillExampleInput() {
        panel.value = 'test'
        bench.inputs = { ...bench.inputs, 'tour-input': 2 }

        return () => { bench.inputs = {} }
    }

    const canvas = selector => () => canvasRef.value?.querySelector(selector) ?? null
    const node = id => canvas(`.vue-flow__node[data-id="tour-${id}"]`)
    const handle = (id, name, side) => canvas(`.vue-flow__node[data-id="tour-${id}"] .vue-flow__handle.${side}[data-handleid="${name}"]`)
    const page = selector => () => document.querySelector(selector)
    const field = selector => page(`[data-tour="formula.panel"] ${selector}`)

    useTour('prijsformule', {
        keepCanvas,
        stageUnwired: () => showStage('unwired'),
        stageBasis: () => showStage('basis'),
        stageFilter: () => showStage('filter'),
        stageTiers: () => showStage('tiers'),
        stageCombination: () => showStage('combination'),
        stageCallout: () => showStage('callout'),
        stageAdjusted: () => showStage('adjusted'),
        stageCondition: () => showStage('condition'),
        stageInput: () => showStage('input'),
        stageOneTime: () => showStage('oneTime'),
        stageCatalog: () => showStage('catalog'),
        stageVisits: () => showStage('visits'),
        stageFull: () => showStage('full'),
        wireLater,
        selectMachines: () => selectExample('machines'),
        selectCount: () => selectExample('count'),
        selectPrice: () => selectExample('price'),
        selectFilter: () => selectExample('filter'),
        selectCombination: () => selectExample('combination'),
        selectPackage: () => selectExample('package'),
        selectCallout: () => selectExample('callout'),
        selectDiscount: () => selectExample('discount'),
        selectMinimum: () => selectExample('minimum'),
        selectRound: () => selectExample('round'),
        selectCondition: () => selectExample('condition'),
        selectInput: () => selectExample('input'),
        selectStartup: () => selectExample('startup'),
        selectCatalog: () => selectExample('catalog'),
        selectContract: () => selectExample('contract'),
        focusStart: () => focusExamples('machines', 'combination', 'filter'),
        focusMiddle: () => focusExamples('count', 'rest-count', 'price', 'rest-price', 'package', 'callout', 'extra'),
        focusEnd: () => focusExamples('sum', 'discount', 'minimum', 'round', 'result', 'startup', 'once'),
        showTrialPanel,
        tryOnContract,
        fillExampleInput,
    }, {
        spots: {
            'formula.node.machines': node('machines'),
            'formula.node.count': node('count'),
            'formula.node.restCount': node('rest-count'),
            'formula.node.price': node('price'),
            'formula.node.restPrice': node('rest-price'),
            'formula.node.result': node('result'),
            'formula.node.filter': node('filter'),
            'formula.node.combination': node('combination'),
            'formula.node.package': node('package'),
            'formula.node.addresses': node('addresses'),
            'formula.node.callout': node('callout'),
            'formula.node.sum': node('sum'),
            'formula.node.discount': node('discount'),
            'formula.node.minimum': node('minimum'),
            'formula.node.round': node('round'),
            'formula.node.condition': node('condition'),
            'formula.node.input': node('input'),
            'formula.node.extra': node('extra'),
            'formula.node.startup': node('startup'),
            'formula.node.once': node('once'),
            'formula.node.catalog': node('catalog'),
            'formula.node.share': node('share'),
            'formula.node.visits': node('visits'),
            'formula.node.perVisit': node('per-visit'),
            'formula.node.contract': node('contract'),
            'formula.node.perPeriod': node('per-period'),
            'formula.handle.machinesOut': handle('machines', 'machines', 'source'),
            'formula.handle.countIn': handle('count', 'machines', 'target'),
            'formula.handle.countOut': handle('count', 'value', 'source'),
            'formula.handle.filterMatch': handle('filter', 'match', 'source'),
            'formula.handle.filterRest': handle('filter', 'rest', 'source'),
            'formula.handle.discountPart': handle('discount', 'part', 'source'),
            'formula.edges.machines': () => [...(canvasRef.value?.querySelectorAll('.vue-flow__edge.formula-edge-machines') ?? [])],
            'formula.controls': canvas('.vue-flow__controls'),
            'formula.minimap': canvas('.vue-flow__minimap'),
            'formula.palette.source': page('[data-palette="source"]'),
            'formula.palette.filter': page('[data-palette="filter"]'),
            'formula.palette.count': page('[data-palette="count"]'),
            'formula.palette.math': page('[data-palette="math"]'),
            'formula.palette.result': page('[data-palette="result"]'),
            'formula.palette.filterModel': page('[data-palette-block="filter_model"]'),
            'formula.palette.filterBrand': page('[data-palette-block="filter_brand"]'),
            'formula.palette.filterAttribute': page('[data-palette-block="filter_attribute"]'),
            'formula.palette.tiers': page('[data-palette-block="tiers"]'),
            'formula.palette.divide': page('[data-palette-block="divide"]'),
            'formula.palette.subtract': page('[data-palette-block="subtract"]'),
            'formula.palette.maximum': page('[data-palette-block="maximum"]'),
            'formula.settings.title': field('[data-formula-field="title"]'),
            'formula.settings.type': field('[data-formula-field="product_type"]'),
            'formula.settings.steps': field('[data-formula-field="tiers"]'),
            'formula.settings.mode': field('[data-formula-field-key="mode"]'),
            'formula.settings.amount': field('[data-formula-field-key="amount"]'),
            'formula.settings.components': field('[data-formula-field="components"]'),
            'formula.settings.fallback': field('[data-formula-field-key="b"]'),
            'formula.settings.compare': field('[data-formula-field-key="operator"]'),
            'formula.settings.name': field('[data-formula-field-key="name"]'),
            'formula.settings.remove': field('[data-formula-remove]'),
        },
    })

    return { touring, heldGraph, clearTimers }
}
