<template>
    <Combobox as="div" v-model="internalValue" @update:modelValue="query = ''">
        <ComboboxLabel v-if="label" class="block text-sm font-medium leading-6 text-gray-900">{{ label }}
        </ComboboxLabel>
        <div :class="[label ? 'mt-2' : '', 'relative']">
            <ComboboxInput
                class="w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                @change="query = $event.target.value" @blur="query = ''" :display-value="(option) => option?.name"
                :default-value="filteredOptions[0]?.name" :placeholder="props.placeholder" />
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
import { computed, ref, watch } from 'vue'
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
})

const internalSearching = ref(props.searching)

watch(() => props.searching, (newVal) => {
    internalSearching.value = newVal;
})

// Emit the update event
const emit = defineEmits(['update:modelValue', 'change'])

// Initialize internalValue based on modelValue
const internalValue = ref(
    props.options.find(option => option.id === Number(props.initialId)) ||
    props.options.find(option => option.id === props.modelValue) ||
    props.options[0]
)

// Check if modelValue is provided, otherwise set it to the first option's id
if (!props.modelValue && props.options.length > 0) {
    emit('update:modelValue', props.options[0].id)
}

// Watch internalValue for changes and emit the selected ID
watch(internalValue, (value) => {
    if (value && value.id !== props.modelValue) {
        emit('update:modelValue', value.id)
    }
})

// Watch props.modelValue for changes to keep internalValue in sync
watch(() => props.modelValue, (newValue) => {
    internalValue.value = props.options.find(option => option.id === newValue) || null
})

const query = ref('')
const filteredOptions = computed(() =>
    query.value === ''
        ? props.options
        : props.options.filter((option) => {
            return option.name.toLowerCase().includes(query.value.toLowerCase())
        }),
)

const debouncedEmitChange = debounce((value) => {
    emit('change', value);
}, 500);

watch(() => query.value, (newVal) => {
    if (newVal !== '') {
        internalSearching.value = true;
        debouncedEmitChange(newVal);
    }
})
</script>
