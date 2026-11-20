/**
 * What the blocks of the price formula editor share: how a block reads in one
 * line, how a number on a wire is written, the colour of each kind of block,
 * and the graph as the server stores it.
 */

import { nlCurrency } from '@/Utilities/Utilities'

/** The look of a plain select in the editor's panels, next to the shared inputs. */
export const SELECT_CLASS = 'block w-full rounded-md border-0 bg-white py-1.5 pl-2 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 sm:text-sm dark:bg-slate-900 dark:text-white dark:ring-slate-500'

/** Models picked from search results, which the page's own catalogue does not hold. */
export const PICKED_MODELS_KEY = Symbol('picked-models')

export const EDITOR_KEY = Symbol('priceFormulaEditor')

/** Dark enough to carry small white text on a block's header. */
export const CATEGORY_COLORS = {
    source: '#15803d',
    filter: '#1d4ed8',
    count: '#6d28d9',
    math: '#334155',
    result: '#b45309',
}

/** What a block dragged from the list carries, so a drop of anything else is ignored. */
export const DRAG_TYPE = 'application/x-price-block'

const numberFormat = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 2 })

export const formatNumber = value => numberFormat.format(Number(value) || 0)

export const machineCount = count => (count === 1 ? '1 machine' : `${count} machines`)

/** A field's value, or the catalogue's default while the block has none of its own. */
export function settingOf(node, definition, key) {
    const value = node.data?.[key]
    if (value !== undefined && value !== null && value !== '') return value

    return definition.fields.find(field => field.key === key)?.default ?? null
}

/** How a block reads in one line on the canvas. */
export function summarize(node, definition, names) {
    const get = key => settingOf(node, definition, key)
    const option = key => definition.fields.find(f => f.key === key)?.options?.[get(key)] ?? get(key)

    switch (node.type) {
        case 'number': return formatNumber(get('value'))
        case 'input': return `${get('name') || 'Invoer'} · standaard ${formatNumber(get('default'))}`
        case 'filter_type': return names.productTypes[get('product_type_id')] ?? 'Kies een producttype'
        case 'filter_model': return names.products[get('product_id')] ?? 'Kies een model'
        case 'filter_brand': return names.brands[get('brand_id')] ?? 'Kies een merk'
        case 'filter_attribute': {
            const attribute = names.attributes[get('product_attribute_id')]
            const unit = get('value') ? names.attributeUnits?.[get('product_attribute_id')] : ''
            return attribute ? `${attribute} ${option('operator')} ${get('value') || '…'}${unit ? ' ' + unit : ''}` : 'Kies een kenmerk'
        }
        case 'combination': {
            const parts = (get('components') || []).map(component => {
                const name = component.product_id
                    ? names.products[component.product_id]
                    : names.productTypes[component.product_type_id]
                return `${component.quantity}× ${name ?? '…'}`
            })
            return parts.length ? parts.join(' + ') : 'Geef de onderdelen van de combinatie op'
        }
        case 'catalog_value': return option('field')
        case 'tiers': {
            const steps = (get('steps') || []).length
            return `${nlCurrency(get('amount'))} per stuk` + (steps ? `, ${steps} ${steps === 1 ? 'stap' : 'stappen'}` : '')
        }
        case 'percentage': return `${formatNumber(get('percent'))}%`
        case 'round': return `op ${option('step')}, ${option('direction')}`
        case 'condition': return `als ${option('operator')} …`
        default: return ''
    }
}

/**
 * The graph in the shape the server stores: nothing the canvas adds for itself.
 * Without the layout it is what a price depends on, so moving a block does not
 * ask the server to work everything out again.
 *
 * Settings without any keys come back from the server as an empty list; they
 * are written as an empty object either way, or a saved graph would never
 * match the one on the canvas.
 */
export function serializeGraph(nodes, edges, { layout = true } = {}) {
    return {
        nodes: nodes.map(node => ({
            id: node.id,
            type: node.type,
            ...(layout ? { position: { x: Math.round(node.position.x), y: Math.round(node.position.y) } } : {}),
            data: settingsOf(node),
        })),
        edges: edges.map(edge => ({
            id: edge.id,
            source: edge.source,
            sourceHandle: edge.sourceHandle,
            target: edge.target,
            targetHandle: edge.targetHandle,
        })),
    }
}

export function settingsOf(node) {
    return !node.data || Array.isArray(node.data) ? {} : node.data
}

/** A wire carrying machines looks different from one carrying a number. */
export function edgeLook(kind) {
    return kind === 'machines'
        ? { class: 'formula-edge-machines', style: { stroke: '#2563eb', strokeWidth: 2.5 } }
        : { class: 'formula-edge-number', style: { stroke: '#94a3b8', strokeWidth: 1.5 } }
}

export function newNodeId(type) {
    return `${type}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}
