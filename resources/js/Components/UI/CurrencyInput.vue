<template>
    <div>
        <label v-if="label" :for="id"
            class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">{{ label }}</label>
        <div :class="[label ? 'mt-2' : '', 'relative']">
            <span aria-hidden="true"
                class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-300 pointer-events-none select-none text-sm">€</span>
            <input ref="inputRef" :name="name" :id="id" :placeholder="placeholder"
                class="transition-colors duration-200 focus:outline-none block w-full border-0 rounded-md py-1.5 pl-7 sm:text-sm sm:leading-6"
                :class="{
                    'text-gray-900 dark:bg-slate-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600': !hasError,
                    'ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600': ring && !hasError,
                    'pr-10 text-red-900 placeholder:text-red-300 border-red-500': hasError,
                    'ring-1 ring-inset ring-red-300 focus:ring-2 focus:ring-inset focus:ring-red-500': ring && hasError,
                    'rounded-r-none': !rightCorners,
                }" :aria-invalid="hasError" :aria-describedby="errorId" />
            <div v-if="hasError" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                <ExclamationCircleIcon class="h-5 w-5 text-red-500" aria-hidden="true" />
            </div>
        </div>
        <Transition enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in" leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1">
            <p v-if="hasError" class="mt-2 text-sm text-red-600" :id="errorId">{{ errorMessage }}</p>
        </Transition>
    </div>
</template>

<script setup>
import { useCurrencyInput } from 'vue-currency-input';
import ExclamationCircleIcon from '@heroicons/vue/20/solid/ExclamationCircleIcon';

defineProps({
    modelValue: { type: [Number, String], default: null },
    label: { type: String, default: '' },
    name: { type: String, default: '' },
    id: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    hasError: { type: Boolean, default: false },
    errorMessage: { type: String, default: '' },
    errorId: { type: String, default: '' },
    rightCorners: { type: Boolean, default: true },
    ring: { type: Boolean, default: true },
});

defineEmits(['update:modelValue']);

const { inputRef } = useCurrencyInput({
    locale: 'nl-NL',
    currency: 'EUR',
    currencyDisplay: 'hidden',
    valueRange: { min: 0 },
    precision: 2,
    hideGroupingSeparatorOnFocus: false,
    hideNegligibleDecimalDigitsOnFocus: false,
});
</script>
