<template>
    <nav class="flex items-center justify-between px-4 sm:px-0" :class="$attrs.class">
        <div class="flex-1">
            <Link v-if="prevUrl" :href="appendParams(prevUrl)"
                class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
            « Vorige
            </Link>
        </div>
        <div class="hidden md:flex space-x-1">
            <template v-for="link in middleLinks" :key="(link.url || '') + '-' + link.label">
                <Link v-if="link.url" :href="appendParams(link.url)"
                    class="px-3 py-1 text-sm font-medium border rounded hover:border-gray-300 hover:text-gray-700"
                    :class="link.active ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'"
                    preserve-scroll>
                <span v-html="decodeEntities(link.label)"></span>
                </Link>
                <span v-else class="px-3 py-1 text-sm font-medium border rounded"
                    :class="link.active ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'">
                    <span v-html="decodeEntities(link.label)"></span>
                </span>
            </template>
        </div>
        <div class="flex-1 text-right">
            <Link v-if="nextUrl" :href="appendParams(nextUrl)"
                class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700" preserve-scroll>
            Volgende »
            </Link>
        </div>
    </nav>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    paginator: { type: Object, required: true },
    params: { type: Object, default: () => ({}) }
})

function decodeEntities(str) {
    const txt = document.createElement('textarea')
    txt.innerHTML = str
    return txt.value
}

const middleLinks = computed(() => {
    const links = props.paginator.links || []
    return links.filter((link) => {
        const lbl = String(link.label || '').toLowerCase()
        return !(
            lbl.includes('laquo') ||
            lbl.includes('raquo') ||
            lbl.includes('previous') ||
            lbl.includes('next') ||
            lbl.includes('vorige') ||
            lbl.includes('volgende') ||
            lbl.includes('«') ||
            lbl.includes('»')
        )
    })
})

const prevUrl = computed(() => props.paginator.links?.[0]?.url || props.paginator.prev_page_url || null)
const nextUrl = computed(() => props.paginator.links?.[props.paginator.links.length - 1]?.url || props.paginator.next_page_url || null)

function appendParams(url) {
    const entries = Object.entries(props.params || {}).filter((pair) => {
        const v = pair[1]
        return v !== undefined && v !== null && String(v) !== ''
    })
    if (!url || entries.length === 0) return url
    const hasQuery = url.includes('?')
    const [base, existing] = hasQuery ? url.split('?') : [url, '']
    const usp = new URLSearchParams(existing)
    for (const [k, v] of entries) usp.set(k, v)
    const qs = usp.toString()
    return qs ? `${base}?${qs}` : base
}
</script>
