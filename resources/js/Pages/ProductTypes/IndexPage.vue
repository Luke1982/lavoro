<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent title="Producttypen" subtitle="Hieronder een lijst van alle producttypen"
            search-url="/producttypes" search-label="Zoek binnen producttypen"
            search-placeholder="bijv. 'Viergastester'" add-label="Voeg producttype toe"
            @add="() => typeFormRef?.show()" />
    </div>
    <div class="mt-0 mb-4" v-auto-animate>
        <CreateRecordForm ref="typeFormRef" external-trigger action="/producttypes" :fields="typeFields"
            add-button-label="Voeg producttype toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerTypes" @update="onCellUpdate"
            @add-child="onAddChild" urlBase="producttypes" :nestable="treeMode" depthKey="_depth" />
        <div v-if="!innerTypes.length" class="text-center text-gray-500 p-4">
            <p>Geen producttypen gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw producttype toe.</p>
        </div>
    </BoxComponent>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';

const typeFormRef = ref(null)

const props = defineProps({
    productTypes: { type: Array, required: true },
    treeMode:     { type: Boolean, default: true },
    search:       { type: String, default: '' },
})

const typeFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', type: 'number', default: 0 },
]

const headers = [
    { key: 'name',                     label: 'Naam',                  fieldtype: 'text',   width: 50 },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)',  fieldtype: 'number', width: 25 },
    { key: 'products_count',           label: 'Producten',             fieldtype: 'count',  tooltipKey: 'products_tooltip', width: 10 },
]

// --- tree helpers ---

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

function flattenTree(items, depth = 0) {
    const result = []
    for (const item of items) {
        const { children, ...rest } = item
        const prods = getAllProducts(item)
        result.push({
            ...rest,
            children,
            _depth: depth,
            products_count:   prods.length,
            products_tooltip: buildTooltip(prods),
        })
        for (const child of (children ?? [])) {
            result.push(...flattenTree([child], depth + 1))
        }
    }
    return result
}

const innerTypes = computed(() => {
    if (props.treeMode) return flattenTree(props.productTypes)
    return props.productTypes.map(t => ({
        ...t,
        _depth: 0,
        products_count:   (t.products ?? []).length,
        products_tooltip: buildTooltip(t.products ?? []),
    }))
})

// --- actions ---

const form = useForm({ name: null, typical_certificate_days: null, parent_id: null })

function onCellUpdate({ item }) {
    form.transform(() => ({ ...item }))
        .put(`/producttypes/${item.id}`, { preserveScroll: true })
}

function onAddChild({ parentId, name }) {
    useForm({ name, parent_id: parentId })
        .post('/producttypes', { preserveScroll: true })
}
</script>
