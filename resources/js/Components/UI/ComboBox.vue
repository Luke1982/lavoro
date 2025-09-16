<template>
    <Combobox as="div" v-model="internalValue" @update:modelValue="onSelect" :multiple="multiple">
        <ComboboxLabel v-if="label" class="block text-xs font-light mb-1.5 lg:hidden text-gray-600 dark:text-slate-300">
            {{ label }}
            <ListBulletIcon v-if="multiple" class="inline size-5 ml-1 text-gray-400"
                v-tooltip="'Meerdere selecties mogelijk'" />
        </ComboboxLabel>
        <div :class="[label ? 'mt-2' : '', 'relative']">
            <ComboboxInput
                class="w-full rounded-md border-0 bg-white dark:bg-slate-800 py-1.5 pl-3 pr-10 text-gray-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-slate-500 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:focus:ring-indigo-500 sm:text-sm sm:leading-6 disabled:bg-gray-100 dark:disabled:bg-slate-700"
                @change="query = $event.target.value" @blur="onBlur" @focus="onFocus" :display-value="displayValue"
                ref="inputRef" :default-value="filteredOptions[0]?.name" :placeholder="props.placeholder"
                :disabled="props.disabled" />
            <ComboboxButton class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 focus:outline-none"
                v-if="!internalSearching || !hasExternalSearching">
                <ChevronUpDownIcon class="h-5 w-5 text-gray-400 dark:text-slate-400" aria-hidden="true" />
            </ComboboxButton>
            <ArrowPathIcon v-if="internalSearching && hasExternalSearching"
                class="absolute inset-y-0 right-0 h-5 w-5 text-gray-400 dark:text-slate-400 animate-spin mr-2 mt-2"
                aria-hidden="true" />

            <ComboboxOptions v-if="filteredOptions.length > 0"
                class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white dark:bg-slate-800 py-1 text-base shadow-lg ring-1 ring-black/5 dark:ring-slate-600 focus:outline-none sm:text-sm">
                <ComboboxOption v-for="option in filteredOptions" :key="option.id" :value="option" as="template"
                    v-slot="{ active, selected }">
                    <li
                        :class="['relative cursor-default select-none py-2 pl-8 pr-4', active ? 'bg-indigo-600 text-white dark:bg-indigo-500' : 'text-gray-900 dark:text-slate-100']">
                        <span :class="['block truncate', selected && 'font-semibold']">
                            {{ option.name }}
                        </span>

                        <span v-if="selected"
                            :class="['absolute inset-y-0 left-0 flex items-center pl-1.5', active ? 'text-white' : 'text-indigo-600 dark:text-indigo-400']">
                            <CheckIcon class="h-5 w-5" aria-hidden="true" />
                        </span>
                    </li>
                </ComboboxOption>
            </ComboboxOptions>
        </div>
    </Combobox>
</template>

<script setup>
import { computed, ref, watch, onMounted, nextTick } from 'vue'
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid'
import {
    Combobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxLabel,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/vue'
import { debounce } from 'lodash';
import { ArrowPathIcon, ListBulletIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    modelValue: [String, Number, Array],
    options: {
        type: Array,
        required: true,
    },
    initialId: [String, Number],
    initialIds: { type: Array, default: () => [] },
    label: String,
    searching: {
        type: Boolean,
        default: false,
    },
    hasExternalSearching: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: 'Type om te zoeken...',
    },
    emitValue: {
        type: Boolean,
        default: false,
    },
    multiple: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

const inputRef = ref(null)
const emit = defineEmits(['update:modelValue', 'change'])

const internalSearching = ref(props.searching)
watch(() => props.searching, (n) => {
    internalSearching.value = n
})

// Determine the initial internalValue candidate
const resolveOption = (id) => props.options.find(o => o.id === id) || null
const resolveOptions = (ids) => Array.isArray(ids) ? ids.map(resolveOption).filter(Boolean) : []

const internalValue = ref(
    props.multiple
        ? (Array.isArray(props.modelValue) && props.modelValue.length
            ? resolveOptions(props.modelValue)
            : (props.initialIds?.length ? resolveOptions(props.initialIds) : []))
        : (resolveOption(props.modelValue) || resolveOption(props.initialId) || null)
)

// If parent hasn’t supplied a modelValue, but we have an initialId, emit it once on mount
onMounted(() => {
    if (!props.multiple) {
        if ((props.modelValue === undefined || props.modelValue === null) && props.initialId != null) {
            const initial = resolveOption(props.initialId)
            if (initial) emit('update:modelValue', props.emitValue ? initial.name : initial.id)
        }
    } else {
        if ((!Array.isArray(props.modelValue) || props.modelValue.length === 0) && props.initialIds?.length) {
            const selected = resolveOptions(props.initialIds)
            if (selected.length) emit('update:modelValue', props.emitValue ? selected.map(o => o.name) : selected.map(o => o.id))
        }
    }
})

// Keep internalValue → modelValue in sync, but only emit when genuinely different
watch(internalValue, (val) => {
    if (!props.multiple) {
        if (val && val.id !== props.modelValue) emit('update:modelValue', props.emitValue ? val.name : val.id)
    } else {
        const ids = Array.isArray(val) ? val.map(v => v.id) : []
        if (JSON.stringify(ids) !== JSON.stringify(props.modelValue)) emit('update:modelValue', props.emitValue ? (Array.isArray(val) ? val.map(v => v.name) : []) : ids)
    }
})

// Keep internalValue updated when external modelValue changes
watch(() => props.modelValue, (newVal) => {
    if (!props.multiple) {
        const option = resolveOption(newVal)
        internalValue.value = option || null
    } else {
        internalValue.value = resolveOptions(newVal)
    }
})

const query = ref('')
const filteredOptions = computed(() =>
    query.value === ''
        ? props.options
        : props.options.filter((option) =>
            option.name.toLowerCase().includes(query.value.toLowerCase())
        )
)

// display logic
const isFocused = ref(false)
function onFocus() {
    isFocused.value = true
    query.value = ''
}
function onBlur() {
    isFocused.value = false
    query.value = ''
}
const displayValue = option => {
    if (props.multiple) {
        if (isFocused.value) return ''
        const arr = Array.isArray(option) ? option : internalValue.value
        return Array.isArray(arr) ? arr.map(o => o?.name).filter(Boolean).join(', ') : ''
    }
    return isFocused.value ? '' : option?.name
}

const debouncedEmitChange = debounce((value) => {
    emit('change', value)
}, 500)

watch(() => query.value, (newVal) => {
    if (newVal !== '') {
        internalSearching.value = true
        debouncedEmitChange(newVal)
    }
})

function onSelect(newOption) {
    internalValue.value = newOption
    if (!props.multiple) {
        emit('update:modelValue', props.emitValue ? newOption.name : newOption.id)
        query.value = ''
        nextTick(() => {
            setTimeout(() => {
                if (!inputRef.value) return
                inputRef.value.el.blur()
            }, 100)
        })
    } else {
        const arr = Array.isArray(newOption) ? newOption : internalValue.value
        const payload = props.emitValue ? arr.map(o => o.name) : arr.map(o => o.id)
        emit('update:modelValue', payload)
        query.value = ''
    }
}
</script>
