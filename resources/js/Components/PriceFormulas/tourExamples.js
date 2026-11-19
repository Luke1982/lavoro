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

/** Every machine at one price: the shape a new formula starts from. */
function basis({ wired = true } = {}) {
    return graph([
        node('machines', 'machines', 0, 1),
        node('count', 'count', 1, 1),
        node('price', 'tiers', 2, 1, { title: 'Per machine', amount: 50, steps: [], mode: 'graduated' }),
        node('result', 'output_periodic', 3, 1),
    ], [
        ...(wired ? [edge('machines', 'machines', 'count', 'machines')] : []),
        edge('count', 'value', 'price', 'quantity'),
        edge('price', 'value', 'result', 'value'),
    ])
}

/** One type at its own price, everything else at another. */
function filtered(types, { steps = [], resultColumn = 5 } = {}) {
    return graph([
        node('machines', 'machines', 0, 1),
        node('filter', 'filter_type', 1, 1, { title: types[0]?.short ?? 'Filter op type', product_type_id: types[0]?.id ?? null }),
        node('count', 'count', 2, 0.4),
        node('rest-count', 'count', 2, 1.8),
        node('price', 'tiers', 3, 0.3, { title: (types[0]?.short ?? 'Dit type') + ' per stuk', amount: 50, steps, mode: 'graduated' }),
        node('rest-price', 'tiers', 3, 1.8, { title: 'Overige per stuk', amount: 30, steps: [], mode: 'graduated' }),
        node('sum', 'sum', 4, 1),
        node('result', 'output_periodic', resultColumn, 1),
    ], [
        edge('machines', 'machines', 'filter', 'machines'),
        edge('filter', 'match', 'count', 'machines'),
        edge('filter', 'rest', 'rest-count', 'machines'),
        edge('count', 'value', 'price', 'quantity'),
        edge('rest-count', 'value', 'rest-price', 'quantity'),
        edge('price', 'value', 'sum', 'values'),
        edge('rest-price', 'value', 'sum', 'values'),
        ...(resultColumn === 5 ? [edge('sum', 'value', 'result', 'value')] : []),
    ])
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

function combination(types) {
    const withFilter = moved(filtered(types, { steps: [{ from: 3, amount: 40 }] }), 1)
    const shifted = graph(withFilter.nodes.filter((item) => item.id !== 'tour-machines'), withFilter.edges.filter((item) => item.source !== 'tour-machines'))

    return merge(shifted, graph([
        node('machines', 'machines', 0, 1),
        node('combination', 'combination', 1, 1, {
            title: (types[0]?.short ?? 'Type A') + ' + ' + (types[1]?.short ?? 'type B'),
            components: [
                { product_type_id: types[0]?.id ?? null, product_id: null, quantity: 1 },
                { product_type_id: types[1]?.id ?? null, product_id: null, quantity: 1 },
            ],
        }),
        node('package', 'multiply', 3, -0.9, { title: 'Pakketprijs', b: 120 }),
    ], [
        edge('machines', 'machines', 'combination', 'machines'),
        edge('combination', 'rest', 'filter', 'machines'),
        edge('combination', 'count', 'package', 'a'),
        edge('package', 'value', 'sum', 'values'),
    ]))
}

function adjusted(types) {
    return merge(filtered(types, { steps: [{ from: 3, amount: 40 }], resultColumn: 8 }), callOut(), graph([
        node('discount', 'percentage', 5, 1, { title: 'Contractkorting', percent: -5 }),
        node('minimum', 'minimum', 6, 1, { title: 'Nooit onder honderd', b: 100 }),
        node('round', 'round', 7, 1, { step: '5', direction: 'up' }),
    ], [
        edge('sum', 'value', 'discount', 'value'),
        edge('discount', 'result', 'minimum', 'a'),
        edge('minimum', 'value', 'round', 'value'),
        edge('round', 'value', 'result', 'value'),
    ]))
}

function condition() {
    return graph([
        node('machines', 'machines', 0, 1),
        node('count', 'count', 1, 1),
        node('price', 'tiers', 2, 0.3, { title: 'Per machine', amount: 50, steps: [], mode: 'graduated' }),
        node('condition', 'condition', 2, 1.8, { title: 'Vanaf vijf machines', operator: '>=', compare: 5, then: -25, else: 0 }),
        node('sum', 'sum', 3, 1),
        node('result', 'output_periodic', 4, 1),
    ], [
        edge('machines', 'machines', 'count', 'machines'),
        edge('count', 'value', 'price', 'quantity'),
        edge('count', 'value', 'condition', 'value'),
        edge('price', 'value', 'sum', 'values'),
        edge('condition', 'value', 'sum', 'values'),
        edge('sum', 'value', 'result', 'value'),
    ])
}

function input(types) {
    return merge(filtered(types), graph([
        node('input', 'input', 2, 3.2, { name: 'Extra bezoeken per jaar', default: 0 }),
        node('extra', 'multiply', 3, 3.2, { title: 'Extra bezoeken', b: 60 }),
    ], [
        edge('input', 'value', 'extra', 'a'),
        edge('extra', 'value', 'sum', 'values'),
    ]))
}

function oneTime() {
    return merge(basis(), graph([
        node('startup', 'number', 2, 2.4, { title: 'Opstartkosten', value: 150 }),
        node('once', 'output_one_time', 3, 2.4),
    ], [
        edge('startup', 'value', 'once', 'value'),
    ]))
}

function catalog() {
    return graph([
        node('machines', 'machines', 0, 1),
        node('catalog', 'catalog_value', 1, 1, { field: 'retail_price' }),
        node('share', 'multiply', 2, 1, { title: 'Acht procent per jaar', b: 0.08 }),
        node('result', 'output_periodic', 3, 1),
    ], [
        edge('machines', 'machines', 'catalog', 'machines'),
        edge('catalog', 'value', 'share', 'a'),
        edge('share', 'value', 'result', 'value'),
    ])
}

function visits() {
    return graph([
        node('machines', 'machines', 0, 1),
        node('visits', 'visits', 1, 1),
        node('per-visit', 'tiers', 2, 1, { title: 'Per beurt', amount: 45, steps: [], mode: 'graduated' }),
        node('contract', 'contract', 2, 2.4),
        node('per-period', 'divide', 3, 1.4, { title: 'Per prijsperiode', b: 1 }),
        node('result', 'output_periodic', 4, 1.4),
    ], [
        edge('machines', 'machines', 'visits', 'machines'),
        edge('visits', 'value', 'per-visit', 'quantity'),
        edge('per-visit', 'value', 'per-period', 'a'),
        edge('contract', 'periods', 'per-period', 'b'),
        edge('per-period', 'value', 'result', 'value'),
    ])
}

/** Everything together: the formula the last chapter walks through. */
function full(types) {
    const base = combination(types)
    const withoutResult = graph(
        base.nodes.filter((item) => item.id !== 'tour-result'),
        base.edges.filter((item) => item.target !== 'tour-result'),
    )

    return merge(withoutResult, moved(callOut(), 0, 0.3), graph([
        node('input', 'input', 3, 4.2, { name: 'Extra bezoeken per jaar', default: 0 }),
        node('extra', 'multiply', 4, 4.2, { title: 'Extra bezoeken', b: 60 }),
        node('discount', 'percentage', 6, 1, { title: 'Contractkorting', percent: -5 }),
        node('minimum', 'minimum', 7, 1, { title: 'Nooit onder honderd', b: 100 }),
        node('round', 'round', 8, 1, { step: '5', direction: 'up' }),
        node('result', 'output_periodic', 9, 1),
        node('startup', 'number', 8, 2.6, { title: 'Opstartkosten', value: 150 }),
        node('once', 'output_one_time', 9, 2.6),
    ], [
        edge('input', 'value', 'extra', 'a'),
        edge('extra', 'value', 'sum', 'values'),
        edge('sum', 'value', 'discount', 'value'),
        edge('discount', 'result', 'minimum', 'a'),
        edge('minimum', 'value', 'round', 'value'),
        edge('round', 'value', 'result', 'value'),
        edge('startup', 'value', 'once', 'value'),
    ]))
}

/** Every stage by name; the rondleiding's actions put one of these on the canvas. */
export function tourStage(name, types) {
    switch (name) {
        case 'unwired': return basis({ wired: false })
        case 'basis': return basis()
        case 'filter': return filtered(types)
        case 'tiers': return filtered(types, { steps: [{ from: 3, amount: 40 }] })
        case 'combination': return combination(types)
        case 'callout': return merge(filtered(types), callOut())
        case 'adjusted': return adjusted(types)
        case 'condition': return condition()
        case 'input': return input(types)
        case 'oneTime': return oneTime()
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
