/**
 * The example formulas the rondleiding builds on the canvas, one stage per
 * thing it explains. Each stage is a whole graph, so going back a step puts back
 * exactly what was there; the person's own formula returns when the chapter
 * ends. Nothing here is ever saved.
 *
 * `types` are two machine types of this company that have machines, so the
 * example counts something real in the trial run; without them the filters say
 * which type is missing, which is a lesson in itself.
 */

const COLUMN = 340
const ROW = 180

const at = (column, row) => ({ x: column * COLUMN, y: row * ROW })

function node(id, type, column, row, data = {}) {
    return { id: 'tour-' + id, type, position: at(column, row), data }
}

function edge(source, sourceHandle, target, targetHandle) {
    return {
        id: `tour-e-${source}-${sourceHandle}-${target}-${targetHandle}`,
        source: 'tour-' + source,
        sourceHandle,
        target: 'tour-' + target,
        targetHandle,
    }
}

function graph(nodes, edges) {
    return { nodes, edges }
}

function merge(...graphs) {
    const nodes = new Map()
    const edges = new Map()
    graphs.forEach((part) => {
        part.nodes.forEach((item) => nodes.set(item.id, item))
        part.edges.forEach((item) => edges.set(item.id, item))
    })

    return graph([...nodes.values()], [...edges.values()])
}

function moved(part, columns, rows = 0) {
    return graph(part.nodes.map((item) => ({
        ...item,
        position: { x: item.position.x + columns * COLUMN, y: item.position.y + rows * ROW },
    })), part.edges)
}

/** The staffel the examples use: from the third piece on, 40 instead of 50. */
const TIERED = [{ from: 3, amount: 40 }]

/** The price per period, taking what block `from` comes to. */
function pricedFrom(from, column, row = 1) {
    return graph([node('result', 'output_periodic', column, row)], [edge(from, 'value', 'result', 'value')])
}

/**
 * Every machine at one price: the shape a new formula starts from. The machines
 * go straight into the price, which counts them.
 */
function basis({ wired = true } = {}) {
    return merge(graph([
        node('machines', 'machines', 0, 1),
        node('price', 'tiers', 1, 1, { title: 'Per machine', amount: 50, steps: [], mode: 'graduated' }),
    ], wired ? [edge('machines', 'machines', 'price', 'quantity')] : []), pricedFrom('price', 2))
}

/** The chosen types at their own price, everything else at another, added up. */
function filtered(types, { steps = [], chosen = types.slice(0, 1) } = {}) {
    return graph([
        node('machines', 'machines', 0, 1),
        node('filter', 'filter_type', 1, 1, {
            title: chosen.map(type => type.name).join(' + ') || 'Filter op type',
            product_type_ids: chosen.map(type => type.id),
        }),
        node('price', 'tiers', 2, 0.3, { title: (types[0]?.name ?? 'Dit type') + ' per stuk', amount: 50, steps, mode: 'graduated' }),
        node('rest-price', 'tiers', 2, 1.8, { title: 'Overige per stuk', amount: 30, steps: [], mode: 'graduated' }),
        node('sum', 'sum', 3, 1),
    ], [
        edge('machines', 'machines', 'filter', 'machines'),
        edge('filter', 'match', 'price', 'quantity'),
        edge('filter', 'rest', 'rest-price', 'quantity'),
        edge('price', 'value', 'sum', 'values'),
        edge('rest-price', 'value', 'sum', 'values'),
    ])
}

function callOut() {
    return graph([
        node('addresses', 'addresses', 1, 3),
        node('callout', 'multiply', 2, 3, { title: 'Voorrijkosten', b: 35 }),
    ], [
        edge('machines', 'machines', 'addresses', 'machines'),
        edge('addresses', 'value', 'callout', 'a'),
        edge('callout', 'value', 'sum', 'values'),
    ])
}

/** Pairs on one address at a package price first; what is left goes through the filter. */
function combination(types) {
    const sold = moved(filtered(types, { steps: TIERED }), 1)

    return merge(graph(sold.nodes, sold.edges.filter((item) => item.source !== 'tour-machines')), graph([
        node('machines', 'machines', 0, 1),
        node('combination', 'combination', 1, 1, {
            title: (types[0]?.name ?? 'Type A') + ' + ' + (types[1]?.name ?? 'type B'),
            components: [
                { product_type_id: types[0]?.id ?? null, product_id: null, quantity: 1 },
                { product_type_id: types[1]?.id ?? null, product_id: null, quantity: 1 },
            ],
        }),
        node('package', 'multiply', 3, -1, { title: 'Pakketprijs', b: 120 }),
    ], [
        edge('machines', 'machines', 'combination', 'machines'),
        edge('combination', 'rest', 'filter', 'machines'),
        edge('combination', 'count', 'package', 'a'),
        edge('package', 'value', 'sum', 'values'),
    ]))
}

/** Five percent off the sum, never under a hundred, rounded up to five euros. */
function adjustments(column) {
    return graph([
        node('discount', 'percentage', column, 1, { title: 'Contractkorting', percent: -5 }),
        node('minimum', 'minimum', column + 1, 1, { title: 'Nooit onder honderd', b: 100 }),
        node('round', 'round', column + 2, 1, { step: '5', direction: 'up' }),
    ], [
        edge('sum', 'value', 'discount', 'value'),
        edge('discount', 'result', 'minimum', 'a'),
        edge('minimum', 'value', 'round', 'value'),
    ])
}

/** The machines go into the price and into the comparison; both count them. */
function condition() {
    return merge(graph([
        node('machines', 'machines', 0, 1),
        node('price', 'tiers', 1, 0.3, { title: 'Per machine', amount: 50, steps: [], mode: 'graduated' }),
        node('condition', 'condition', 1, 1.8, { title: 'Vanaf vijf machines', operator: '>=', compare: 5, then: -25, else: 0 }),
        node('sum', 'sum', 2, 1),
    ], [
        edge('machines', 'machines', 'price', 'quantity'),
        edge('machines', 'machines', 'condition', 'value'),
        edge('price', 'value', 'sum', 'values'),
        edge('condition', 'value', 'sum', 'values'),
    ]), pricedFrom('sum', 3))
}

/** Extra visits filled in per contract, at sixty euros each. */
function extraVisits(column, row) {
    return graph([
        node('input', 'input', column, row, { name: 'Extra bezoeken per jaar', default: 0 }),
        node('extra', 'multiply', column + 1, row, { title: 'Extra bezoeken', b: 60 }),
    ], [
        edge('input', 'value', 'extra', 'a'),
        edge('extra', 'value', 'sum', 'values'),
    ])
}

/** A start-up fee of 150, charged once. */
function startUp(column, row) {
    return graph([
        node('startup', 'number', column, row, { title: 'Opstartkosten', value: 150 }),
        node('once', 'output_one_time', column + 1, row),
    ], [
        edge('startup', 'value', 'once', 'value'),
    ])
}

function catalog() {
    return merge(graph([
        node('machines', 'machines', 0, 1),
        node('catalog', 'catalog_value', 1, 1, { field: 'retail_price' }),
        node('share', 'multiply', 2, 1, { title: 'Acht procent per jaar', b: 0.08 }),
    ], [
        edge('machines', 'machines', 'catalog', 'machines'),
        edge('catalog', 'value', 'share', 'a'),
    ]), pricedFrom('share', 3))
}

function visits() {
    return merge(graph([
        node('machines', 'machines', 0, 1),
        node('visits', 'visits', 1, 1),
        node('per-visit', 'tiers', 2, 1, { title: 'Per beurt', amount: 45, steps: [], mode: 'graduated' }),
        node('contract', 'contract', 2, 2.4),
        node('per-period', 'divide', 3, 1.4, { title: 'Per prijsperiode', b: 1 }),
    ], [
        edge('machines', 'machines', 'visits', 'machines'),
        edge('visits', 'value', 'per-visit', 'quantity'),
        edge('per-visit', 'value', 'per-period', 'a'),
        edge('contract', 'periods', 'per-period', 'b'),
    ]), pricedFrom('per-period', 4, 1.4))
}

/** Everything together: the formula the last chapter walks through. */
function full(types) {
    return merge(
        combination(types),
        moved(callOut(), 0, 0.3),
        extraVisits(2, 4.2),
        adjustments(5),
        pricedFrom('round', 8),
        startUp(7, 2.6),
    )
}

/** Every stage by name; the rondleiding's actions put one of these on the canvas. */
export function tourStage(name, types) {
    switch (name) {
        case 'unwired': return basis({ wired: false })
        case 'basis': return basis()
        case 'filter': return merge(filtered(types), pricedFrom('sum', 4))
        case 'bothTypes': return merge(filtered(types, { chosen: types.slice(0, 2) }), pricedFrom('sum', 4))
        case 'tiers': return merge(filtered(types, { steps: TIERED }), pricedFrom('sum', 4))
        case 'combination': return merge(combination(types), pricedFrom('sum', 5))
        case 'callout': return merge(filtered(types), callOut(), pricedFrom('sum', 4))
        case 'adjusted': return merge(filtered(types, { steps: TIERED }), callOut(), adjustments(4), pricedFrom('round', 7))
        case 'condition': return condition()
        case 'input': return merge(filtered(types), extraVisits(1, 3.2), pricedFrom('sum', 4))
        case 'oneTime': return merge(basis(), startUp(1, 2.4))
        case 'catalog': return catalog()
        case 'visits': return visits()
        case 'full': return full(types)
        default: return basis()
    }
}

/**
 * The made-up customer the try-out starts with, and the rondleiding works its
 * examples out on: machines of both types, on two addresses, so a combination,
 * a filter and a per-address line all have something to show.
 */
export function tourSample(types) {
    const [first, second] = types

    return [
        { quantity: 4, product_type_id: first?.id ?? null, product_id: null, address: 1 },
        { quantity: 1, product_type_id: second?.id ?? null, product_id: null, address: 1 },
        { quantity: 2, product_type_id: first?.id ?? null, product_id: null, address: 2 },
        { quantity: 1, product_type_id: second?.id ?? null, product_id: null, address: 2 },
    ]
}
