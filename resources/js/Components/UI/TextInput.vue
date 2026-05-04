<template>
    <div>
        <label :for="id" class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">{{ label
            }}</label>
        <div :class="[label === '' ? '' : 'mt-2', 'relative']">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <iconLeft v-if="iconLeft" class="h-5 w-5 text-gray-400 dark:text-gray-300" aria-hidden="true"
                    v-bind="iconLeftProps" />
            </div>
            <input :type="type" :name="name" :id="id" v-model="internalValue" :autocomplete="autocomplete" class="transition-colors duration-200" :class="{
                'dark:bg-slate-900 block w-full border-0 rounded-md py-1.5 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6': !hasError,
                'block w-full border-0 rounded-md py-1.5 pr-10 text-red-900 ring-1 ring-inset ring-red-300 placeholder:text-red-300 focus:ring-2 focus:ring-inset focus:ring-red-500 sm:text-sm sm:leading-6 border-red-500': hasError,
                'pl-10': iconLeft,
                'pl-2': !iconLeft,
                'rounded-r-none': !rightCorners,
            }" :aria-invalid="hasError" :aria-describedby="errorId" ref="inputRef" :placeholder="placeholder" />
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                <ExclamationCircleIcon v-if="hasError" class="h-5 w-5 text-red-500" aria-hidden="true" />
            </div>
        </div>
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <p v-if="hasError" class="mt-2 text-sm text-red-600" :id="errorId">{{ errorMessage }}</p>
        </Transition>
    </div>
</template>

<script setup>
import ExclamationCircleIcon from '@heroicons/vue/20/solid/ExclamationCircleIcon';

import { ref } from 'vue'
const inputRef = ref(null)

defineExpose({
    focus: () => {
        inputRef.value?.focus()
    }
})
</script>

<script>
export default {
    name: 'TextInput',
    props: {
        label: {
            type: String,
            required: false,
            default: ''
        },
        modelValue: {
            type: [String, Number],
            required: true
        },
        errorMessage: {
            type: String,
            default: ''
        },
        hasError: {
            type: Boolean,
            default: false
        },
        type: {
            type: String,
            default: 'text'
        },
        name: {
            type: String,
            required: false
        },
        id: {
            type: String,
            required: false
        },
        autocomplete: {
            type: String,
            default: 'off'
        },
        errorId: {
            type: String,
            default: ''
        },
        iconLeft: {
            type: [Object, Boolean, Function],
            default: false
        },
        iconLeftProps: {
            type: Object,
            default: () => ({})
        },
        placeholder: {
            type: String,
            default: ''
        },
        rightCorners: {
            type: Boolean,
            default: true
        }
    },
    computed: {
        internalValue: {
            get() {
                return this.modelValue;
            },
            set(value) {
                this.$emit('update:modelValue', value);
            }
        }
    }
};
</script>
