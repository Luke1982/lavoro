<template>
    <div class="mb-4 w-full">
        <label v-if="label" class="block text-sm font-medium text-gray-900">{{ label }}</label>
        <div :class="[label ? 'mt-2' : '', 'relative rounded-md shadow-sm']">
            <TextInput :id="inputId" v-model="internalValue" :placeholder="placeholder" :disabled="inAction"
                :iconLeft="inAction ? ArrowPathIcon : MagnifyingGlassIcon" :iconLeftProps="{
                    class: (inAction ? 'animate-spin ' : '') + 'h-5 w-5 text-gray-400'
                }" class="w-full bg-white" type="search" />
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
    modelValue: { type: [String, Number], default: '' },
})

const emit = defineEmits(['update:modelValue'])

const inAction = ref(false)

const internalValue = ref('')

watch(() => props.modelValue, (v) => {
    if (v !== internalValue.value) internalValue.value = v ?? ''
}, { immediate: true })

const doSearch = debounce((term) => {
    inAction.value = true
    localStorage.setItem(props.localStorageKey, 'true')
    const params = { ...props.otherParams, [props.param]: term }
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
    emit('update:modelValue', val)
    doSearch(val)
})

onMounted(() => {
    if (localStorage.getItem(props.localStorageKey) === 'true') {
        localStorage.removeItem(props.localStorageKey)
        nextTick(() => document.getElementById(props.inputId)?.focus())
    }
})
</script>
