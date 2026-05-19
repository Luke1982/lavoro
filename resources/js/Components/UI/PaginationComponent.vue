<template>
    <nav class="flex items-center justify-between px-4 py-2" :class="$attrs.class"
        v-if="middleLinks.length > 1 || prevUrl || nextUrl">
        <div class="flex-1">
            <Link v-if="prevUrl" :href="appendParams(prevUrl)"
                class="inline-flex items-center text-sm text-gray-500 bg-lavoro-gray-150 px-3 py-3 rounded-md border-1 border-gray-200"
                preserve-scroll>
                <ChevronLeftIcon class="h-4 w-4" />
            </Link>
        </div>
        <div class="hidden md:flex space-x-1">
            <template v-for="link in middleLinks" :key="(link.url || '') + '-' + link.label">
                <Link v-if="link.url" :href="appendParams(link.url)"
                    class="px-4 py-2 text-sm font-medium border rounded-md"
                    :class="link.active ? 'bg-lavoro-blue text-white' : 'border-transparent text-gray-500 dark:text-gray-300'"
                    preserve-scroll>
                    <span v-html="decodeEntities(link.label)"></span>
                </Link>
                <span v-else class="px-3 py-1 text-sm font-medium border rounded"
                    :class="link.active ? 'border-indigo-500 text-indigo-600 dark:border-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-300'">
                    <span v-html="decodeEntities(link.label)"></span>
                </span>
            </template>
        </div>
        <div class="flex-1 text-right">
            <Link v-if="nextUrl" :href="appendParams(nextUrl)"
                class="inline-flex items-center text-sm text-gray-500 bg-lavoro-gray-150 px-3 py-3 rounded-md border-1 border-gray-200"
                preserve-scroll>
                <ChevronRightIcon class="h-4 w-4" />
            </Link>
        </div>
    </nav>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ChevronLeftIcon, ChevronRightIcon } from '@lucide/vue'

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
    if (!url) {
        return url
    }
    // Determine effective params: prefer explicit props.params; otherwise use current URL query
    let effectiveParams = props.params || {}
    const hasExplicitParams = Object.values(effectiveParams).some((v) => v !== undefined && v !== null && String(v) !== '')
    if (!hasExplicitParams && typeof window !== 'undefined') {
        const current = new URLSearchParams(window.location.search)
        const obj = {}
        current.forEach((value, key) => {
            if (String(value) !== '') {
                obj[key] = value
            }
        })
        effectiveParams = obj
    }

    const entries = Object.entries(effectiveParams).filter(([, v]) => v !== undefined && v !== null && String(v) !== '')
    if (entries.length === 0) {
        return url
    }
    const hasQuery = url.includes('?')
    const [base, existing] = hasQuery ? url.split('?') : [url, '']
    const usp = new URLSearchParams(existing)
    for (const [k, v] of entries) {
        if (k === 'page' && usp.has('page')) continue
        usp.set(k, v)
    }
    const qs = usp.toString()
    return qs ? `${base}?${qs}` : base
}
</script>
