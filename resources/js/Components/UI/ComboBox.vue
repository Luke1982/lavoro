<template>
    <Combobox as="div" v-model="internalValue" @update:modelValue="onSelect">
        <ComboboxLabel v-if="label" class="block text-sm font-medium leading-6 text-gray-900">{{ label }}
        </ComboboxLabel>
        <div :class="[label ? 'mt-2' : '', 'relative']">
            <ComboboxInput
                class="w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                @change="query = $event.target.value" @blur="onBlur" @focus="onFocus" :display-value="displayValue"
                ref="inputRef" :default-value="filteredOptions[0]?.name" :placeholder="props.placeholder" />
            <ComboboxButton class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 focus:outline-none"
                v-if="!internalSearching || !hasExternalSearching">
                <ChevronUpDownIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
            </ComboboxButton>
            <ArrowPathIcon v-if="internalSearching && hasExternalSearching"
                class="absolute inset-y-0 right-0 h-5 w-5 text-gray-400 animate-spin mr-2 mt-2" aria-hidden="true" />

            <ComboboxOptions v-if="filteredOptions.length > 0"
                class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                <ComboboxOption v-for="option in filteredOptions" :key="option.id" :value="option" as="template"
                    v-slot="{ active, selected }">
                    <li
                        :class="['relative cursor-default select-none py-2 pl-8 pr-4', active ? 'bg-indigo-600 text-white' : 'text-gray-900']">
                        <span :class="['block truncate', selected && 'font-semibold']">
                            {{ option.name }}
                        </span>

                        <span v-if="selected"
                            :class="['absolute inset-y-0 left-0 flex items-center pl-1.5', active ? 'text-white' : 'text-indigo-600']">
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
import { ArrowPathIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    modelValue: [String, Number],
    options: {
        type: Array,
        required: true,
    },
    initialId: [String, Number],
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
})

const inputRef = ref(null)
const emit = defineEmits(['update:modelValue', 'change'])

const internalSearching = ref(props.searching)
watch(() => props.searching, (n) => {
    internalSearching.value = n
})

// Determine the initial internalValue candidate
const resolveOption = (id) => props.options.find(o => o.id === id) || null

const internalValue = ref(
    resolveOption(props.modelValue) ||
    resolveOption(props.initialId) ||
    null
)

// If parent hasn’t supplied a modelValue, but we have an initialId, emit it once on mount
onMounted(() => {
    if ((props.modelValue === undefined || props.modelValue === null) && props.initialId != null) {
        const initial = resolveOption(props.initialId)
        if (initial) {
            emit('update:modelValue', props.emitValue ? initial.name : initial.id)
        }
    }
})

// Keep internalValue → modelValue in sync, but only emit when genuinely different
watch(internalValue, (val) => {
    if (val && val.id !== props.modelValue) {
        emit('update:modelValue', props.emitValue ? val.name : val.id)
    }
})

// Keep internalValue updated when external modelValue changes
watch(() => props.modelValue, (newVal) => {
    const option = resolveOption(newVal)
    if (option) {
        internalValue.value = option
    } else {
        internalValue.value = null
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
const displayValue = option => (isFocused.value ? '' : option?.name)

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
    emit('update:modelValue', props.emitValue ? newOption.name : newOption.id)
    query.value = ''
    nextTick(() => {
        setTimeout(() => {
            if (!inputRef.value) return
            inputRef.value.el.blur()
        }, 100)
    })
}
</script>
