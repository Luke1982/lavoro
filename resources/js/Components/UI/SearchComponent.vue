<template>
    <div class="w-full">
        <label v-if="label" class="block text-sm font-medium text-gray-900 dark:text-gray-300">{{ label }}</label>
        <div :class="[label ? 'mt-2' : '', 'relative rounded-md shadow-sm']">
            <TextInput :id="inputId" v-model="internalValue" :placeholder="placeholder" :disabled="inAction"
                :iconLeft="inAction ? ArrowPathIcon : MagnifyingGlassIcon" :iconLeftProps="{
                    class: (inAction ? 'animate-spin ' : '') + 'h-5 w-5 text-gray-400'
                }" class="w-full bg-white rounded-md" type="search" />
        </div>
    </div>

</template>

<script setup>
import { ref, watch, onMounted, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import TextInput from '@/Components/UI/TextInput.vue'
import { ArrowPathIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
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
})
</script>
