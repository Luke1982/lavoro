<template>
    <div class="w-full">
        <label v-if="label" class="block text-sm font-medium text-gray-900 dark:text-gray-300">{{ label }}</label>
        <div :class="[label ? 'mt-2' : '', 'relative rounded-md border-gray-200 border-1']">
            <TextInput :id="inputId" v-model="internalValue" :placeholder="placeholder" :disabled="inAction"
                :ring="false" :iconLeft="inAction ? LoaderCircle : Search" :iconLeftProps="{
                    class: (inAction ? 'animate-spin ' : '') + 'h-5 w-5 text-gray-400'
                }" class="w-full bg-white rounded-md pr-20 py-1" type="search" />
            <div
                class="rounded-sm absolute bg-gray-100 right-2 inset-y-2 text-xs p-1 w-12 text-gray-500 text-center flex items-center justify-center pointer-events-none">
                <SquareChevronUpIcon class="inline h-4 w-4 mr-1" />K
            </div>
        </div>
    </div>

</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import TextInput from '@/Components/UI/TextInput.vue'
import { Search, LoaderCircle, SquareChevronUpIcon } from '@lucide/vue'
import debounce from 'lodash/debounce'

const props = defineProps({
    url: { type: String, required: true },
    param: { type: String, default: 'search' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    otherParams: { type: Object, default: () => ({}) },
    localStorageKey: { type: String, default: 'searchInitiated' },
    inputId: { type: String, default: 'searchInput' },
})

const inAction = ref(false)

const internalValue = ref('')

function isSameAsCurrent(params) {
    if (typeof window === 'undefined') {
        return false
    }
    const url = new URL(window.location.href)
    for (const [k, v] of Object.entries(params)) {
        const cur = url.searchParams.get(k) ?? ''
        if (String(cur) !== String(v ?? '')) {
            return false
        }
    }
    return true
}

const doSearch = debounce((term) => {
    const params = { ...props.otherParams, [props.param]: term }
    if (isSameAsCurrent(params)) {
        return
    }
    inAction.value = true
    localStorage.setItem(props.localStorageKey, 'true')
    router.get(props.url, params, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { inAction.value = true },
        onFinish: () => {
            inAction.value = false
            localStorage.removeItem(props.localStorageKey)
            nextTick(() => {
                document.getElementById(props.inputId)?.focus()
            })
        }
    })
}, 500)

watch(internalValue, (val) => {
    doSearch(val)
})

// React to changes in additional params (e.g., filters) and rerun the search with the current term
watch(() => JSON.stringify(props.otherParams ?? {}), () => {
    doSearch(internalValue.value)
})

onMounted(() => {
    if (typeof window !== 'undefined') {
        const current = new URL(window.location.href)
        const term = current.searchParams.get(props.param) ?? ''
        internalValue.value = term
    }
    if (localStorage.getItem(props.localStorageKey) === 'true') {
        localStorage.removeItem(props.localStorageKey)
        nextTick(() => document.getElementById(props.inputId)?.focus())
    }

    function onKeydown(e) {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault()
            document.getElementById(props.inputId)?.focus()
        }
    }

    window.addEventListener('keydown', onKeydown)
    onUnmounted(() => window.removeEventListener('keydown', onKeydown))
})
</script>
