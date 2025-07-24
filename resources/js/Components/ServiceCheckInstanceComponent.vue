<template>
    <div>
        <div class="p-4">
            <fieldset v-if="serviceCheckInstance.service_check.type === 'radio'">
                <legend class="text-sm/6 font-semibold text-gray-900">{{ serviceCheckInstance.service_check.name }}
                </legend>
                <p class="mt-1 text-sm/6 text-gray-600">Kies een van de opties</p>
                <div class="mt-6 space-y-6">
                    <div v-for="value in serviceCheckInstance.service_check.values" :key="value.id"
                        class="flex items-center">
                        <input :id="value.id" name="value" type="radio"
                            class="relative size-4 appearance-none rounded-full border border-gray-300 bg-white before:absolute before:inset-1 before:rounded-full before:bg-white not-checked:before:hidden checked:border-indigo-600 checked:bg-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:border-gray-300 disabled:bg-gray-100 disabled:before:bg-gray-400 forced-colors:appearance-auto forced-colors:before:hidden" />
                        <label :for="value.id" class="ml-3 block text-sm/6 font-medium text-gray-900">{{
                            value.value }}</label>
                    </div>
                </div>
            </fieldset>
            <fieldset v-else-if="serviceCheckInstance.service_check.type === 'checkgroup'">
                <legend class="text-sm/6 font-semibold text-gray-900">{{ serviceCheckInstance.service_check.name }}
                </legend>
                <p class="mt-1 text-sm/6 text-gray-600">Kies een of meerdere van de opties</p>
                <div class="space-y-5">
                    <div class="flex gap-3" v-for="value in serviceCheckInstance.service_check.values" :key="value.id">
                        <div class="flex h-6 shrink-0 items-center">
                            <div class="group grid size-4 grid-cols-1">
                                <input :id="`value-${value.id}`" :name="`value-${value.id}`" type="checkbox"
                                    class="col-start-1 row-start-1 appearance-none rounded-sm border border-gray-300 bg-white checked:border-indigo-600 checked:bg-indigo-600 indeterminate:border-indigo-600 indeterminate:bg-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:border-gray-300 disabled:bg-gray-100 disabled:checked:bg-gray-100 forced-colors:appearance-auto" />
                                <svg class="pointer-events-none col-start-1 row-start-1 size-3.5 self-center justify-self-center stroke-white group-has-disabled:stroke-gray-950/25"
                                    viewBox="0 0 14 14" fill="none">
                                    <path class="opacity-0 group-has-checked:opacity-100" d="M3 8L6 11L11 3.5"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <path class="opacity-0 group-has-indeterminate:opacity-100" d="M3 7H11"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-sm/6">
                            <label :for="`value-${value.id}`" class="font-medium text-gray-900">{{ value.value
                            }}</label>
                        </div>
                    </div>
                </div>
            </fieldset>
            <div class="flex items-center justify-between"
                v-else-if="serviceCheckInstance.service_check.type === 'boolean'">
                <span class="flex grow flex-col">
                    <label class="text-sm/6 font-semibold text-gray-900">{{
                        serviceCheckInstance.service_check.name }}</label>
                    <span class="text-sm text-gray-500" id="availability-description">Zet de schakelaar hiernaast aan of
                        uit</span>
                </span>
                <div
                    class="group relative inline-flex w-11 shrink-0 rounded-full bg-red-400 p-0.5 inset-ring inset-ring-gray-900/5 outline-offset-2 outline-green-600 transition-colors duration-200 ease-in-out has-checked:bg-green-600 has-focus-visible:outline-2 dark:bg-red-400 dark:inset-ring-white/10 dark:outline-green-500 dark:has-checked:bg-green-500">
                    <span
                        class="size-5 rounded-full bg-white shadow-xs ring-1 ring-gray-900/5 transition-transform duration-200 ease-in-out group-has-checked:translate-x-5" />
                    <input type="checkbox" class="absolute inset-0 appearance-none focus:outline-hidden" />
                </div>
            </div>
            <div class="flex flex-col justify-between"
                v-else-if="serviceCheckInstance.service_check.type === 'text' || serviceCheckInstance.service_check.type === 'number'">
                <span class="flex grow flex-col">
                    <label class="text-sm/6 font-semibold text-gray-900 mb-1">{{
                        serviceCheckInstance.service_check.name }}</label>

                </span>
                <div>
                    <input :type="serviceCheckInstance.service_check.type"
                        class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600 sm:text-sm/6"
                        :placeholder="`Vul een ${serviceCheckInstance.service_check.type === 'number' ? 'getal' : 'tekst'} in`" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    serviceCheckInstance: {
        type: Object,
        required: true
    },
    checkTypesWithOptions: {
        type: Array,
        required: true
    }
});
</script>