<template>
    <IndexHeaderComponent title="Producttypen" subtitle="Hieronder een lijst van alle producttypen"
        search-url="/producttypes" search-label="Zoek binnen producttypen"
        search-placeholder="bijv. 'Viergastester'" :paginator="false" add-label="Voeg producttype toe"
        @add="() => typeFormRef?.show()" />
    <div class="mt-0 mb-4" v-auto-animate>
        <CreateRecordForm ref="typeFormRef" external-trigger action="/producttypes" :fields="typeFields"
            add-button-label="Voeg producttype toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="rootTypes.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-6">Naam</div>
                <div class="col-span-3">Keuringsduur (dagen)</div>
                <div class="col-span-2">Producten</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <ProductTypeTreeNode v-for="type in rootTypes" :key="type.id" :type="type" :depth="0" />
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <LayersIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen producttypen gevonden.</p>
                <p class="text-xs mt-1">Probeer een andere zoekterm of voeg een nieuw producttype toe.</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, provide, reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ProductTypeTreeNode from '@/Components/ProductTypes/ProductTypeTreeNode.vue'
import { LayersIcon } from '@lucide/vue'

const typeFormRef = ref(null)
const addingChildTo = reactive({})
const childNames = reactive({})

const props = defineProps({
    productTypes: { type: Array, required: true },
    treeMode: { type: Boolean, default: true },
    search: { type: String, default: '' },
})

const typeFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', type: 'number', default: 0 },
]

// --- tree building ---

const ROW_HEIGHT = 62

function computeNodeHeight(node) {
    const childrenHeight = (node.children ?? []).reduce((sum, c) => sum + computeNodeHeight(c), 0)
    return ROW_HEIGHT + childrenHeight
}

function computeSpineHeight(children) {
    if (!children.length) return 0
    const heightBeforeLastChild = children.slice(0, -1).reduce((sum, c) => sum + computeNodeHeight(c), 0)
    return heightBeforeLastChild + Math.floor(ROW_HEIGHT / 2) - 4
}

function getAllProducts(item) {
    return [
        ...(item.products ?? []),
        ...(item.children ?? []).flatMap(c => getAllProducts(c)),
    ]
}

function buildTooltip(products) {
    if (!products.length) return '<p style="font-size:11px;font-style:italic;margin:0;">Geen producten</p>'
    const header = `<p style="font-size:11px;font-weight:600;margin:0 0 6px;border-bottom:1px solid rgba(255,255,255,.15);padding-bottom:4px;">${products.length} product${products.length !== 1 ? 'en' : ''}</p>`
    const rows = products.slice(0, 12).map(p => {
        const brand = p.brand?.name ? `<span style="font-size:10px;opacity:.65;">${p.brand.name}</span>` : ''
        return `<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:2px 0;">${brand}<span style="font-size:11px;">${p.model}</span></div>`
    }).join('')
    const more = products.length > 12
        ? `<p style="font-size:10px;font-style:italic;margin:6px 0 0;opacity:.65;">... en ${products.length - 12} meer</p>`
        : ''
    return `<div style="min-width:160px;max-width:260px;">${header}${rows}${more}</div>`
}

function buildTree(flat, parentId = null) {
    return flat
        .filter(t => (t.parent_id ?? null) === parentId)
        .map(t => ({ ...t, children: buildTree(flat, t.id) }))
}

function enrichTree(nodes) {
    return nodes.map(t => {
        const children = enrichTree(t.children ?? [])
        const enriched = { ...t, children }
        const prods = getAllProducts(enriched)
        return {
            ...enriched,
            products_count: prods.length,
            products_tooltip: buildTooltip(prods),
            spineHeight: computeSpineHeight(children),
        }
    })
}

const rootTypes = computed(() => {
    if (!props.treeMode) {
        return props.productTypes.map(t => {
            const prods = t.products ?? []
            return { ...t, children: [], products_count: prods.length, products_tooltip: buildTooltip(prods), spineHeight: 0 }
        })
    }
    return enrichTree(buildTree(props.productTypes))
})

// --- actions (provided to all tree nodes) ---

function onUpdate(type, patch) {
    router.patch(`/producttypes/${type.id}`, {
        name: type.name,
        typical_certificate_days: type.typical_certificate_days,
        parent_id: type.parent_id ?? null,
        ...patch,
    }, { preserveScroll: true })
}

function onToggleAdding(id) {
    addingChildTo[id] = !addingChildTo[id]
    if (!addingChildTo[id]) childNames[id] = ''
}

function onSubmitChild(parentId) {
    const name = (childNames[parentId] || '').trim()
    if (!name) return
    useForm({ name, parent_id: parentId }).post('/producttypes', {
        preserveScroll: true,
        onSuccess: () => {
            addingChildTo[parentId] = false
            childNames[parentId] = ''
        },
    })
}

function onDelete(type) {
    const hasChildren = (type.children?.length ?? 0) > 0
    const msg = hasChildren
        ? 'Weet je zeker dat je dit producttype en al zijn subtypes wilt verwijderen?'
        : 'Weet je zeker dat je dit producttype wilt verwijderen?'
    if (!confirm(msg)) return
    router.delete(`/producttypes/${type.id}`, { preserveScroll: true })
}

provide('pt:addingChildTo', addingChildTo)
provide('pt:childNames', childNames)
provide('pt:onUpdate', onUpdate)
provide('pt:onToggleAdding', onToggleAdding)
provide('pt:onSubmitChild', onSubmitChild)
provide('pt:onDelete', onDelete)
</script>
