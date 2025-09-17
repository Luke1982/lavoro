<template>
    <div>
        <div class="p-2 h-full relative" v-auto-animate>
            <div class="ring-gray-200 dark:ring-slate-700/60 ring bg-[#fdfdfd] dark:bg-slate-800 rounded-md p-4 pt-8 h-full relative"
                v-auto-animate>
                <div class="absolute top-2 left-2 flex items-center gap-2" v-if="!readonly">
                    <button type="button" @click="toggle_remarks"
                        class="text-gray-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 bg-white dark:bg-slate-700 shadow-sm rounded-md p-1 ring-1 ring-gray-300 dark:ring-slate-600"
                        v-tooltip="show_remarks ? 'Verberg opmerkingen' : 'Toon opmerkingen'">
                        <ChatBubbleLeftRightIcon class="h-4 w-4" />
                    </button>
                </div>
                <div class="relative" v-auto-animate>
                    <fieldset v-if="serviceCheckInstance.service_check.type === 'radio'">
                        <legend class="text-sm/6 font-semibold text-gray-900 dark:text-slate-100">{{
                            serviceCheckInstance.service_check.name
                            }}
                        </legend>
                        <p class="mt-1 text-sm/6 text-gray-600 dark:text-slate-400">Kies een van de opties</p>
                        <div class="mt-6 space-y-6">
                            <div v-for="value in serviceCheckInstance.service_check.values" :key="value.id"
                                class="flex items-center">
                                <input :id="value.id" name="value" type="radio" v-model="form.values" :value="value.id"
                                    class="relative size-4 appearance-none rounded-full border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 before:absolute before:inset-1 before:rounded-full before:bg-white dark:before:bg-slate-500 not-checked:before:hidden checked:border-indigo-600 checked:bg-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:border-gray-300 disabled:bg-gray-100 dark:disabled:border-slate-700 dark:disabled:bg-slate-800 disabled:before:bg-gray-400 forced-colors:appearance-auto forced-colors:before:hidden" />
                                <label :for="value.id"
                                    class="ml-3 block text-sm/6 font-medium text-gray-900 dark:text-slate-100">{{
                                        value.value }}</label>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset v-else-if="serviceCheckInstance.service_check.type === 'checkgroup'">
                        <legend class="text-sm/6 font-semibold text-gray-900 dark:text-slate-100">{{
                            serviceCheckInstance.service_check.name
                            }}
                        </legend>
                        <p class="mt-1 text-sm/6 text-gray-600 dark:text-slate-400">Kies een of meerdere van de opties
                        </p>
                        <div class="space-y-5">
                            <div class="flex gap-3" v-for="value in serviceCheckInstance.service_check.values"
                                :key="value.id">
                                <div class="flex h-6 shrink-0 items-center">
                                    <div class="group grid size-4 grid-cols-1">
                                        <input :id="`value-${value.id}`" :name="`value-${value.id}`" type="checkbox"
                                            v-model="form.values" :value="value.id"
                                            class="col-start-1 row-start-1 appearance-none rounded-sm border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 checked:border-indigo-600 checked:bg-indigo-600 indeterminate:border-indigo-600 indeterminate:bg-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:border-gray-300 disabled:bg-gray-100 dark:disabled:border-slate-700 dark:disabled:bg-slate-800 disabled:checked:bg-gray-100 forced-colors:appearance-auto" />
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
                                    <label :for="`value-${value.id}`"
                                        class="font-medium text-gray-900 dark:text-slate-100">{{ value.value
                                        }}</label>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                    <div class="flex items-center justify-between"
                        v-else-if="serviceCheckInstance.service_check.type === 'boolean'">
                        <span class="flex grow flex-col">
                            <label class="text-sm/6 font-semibold text-gray-900 dark:text-slate-100">{{
                                serviceCheckInstance.service_check.name }}</label>
                            <span class="text-sm text-gray-500 dark:text-slate-400">Zet de schakelaar aan of uit</span>
                        </span>
                        <SwitchComponent v-model="form.switch_state" />
                    </div>
                    <div class="flex flex-col justify-between"
                        v-else-if="serviceCheckInstance.service_check.type === 'text' || serviceCheckInstance.service_check.type === 'number'">
                        <span class="flex grow flex-col">
                            <label class="text-sm/6 font-semibold text-gray-900 dark:text-slate-100 mb-1">{{
                                serviceCheckInstance.service_check.name }}</label>

                        </span>
                        <div>
                            <input
                                :type="serviceCheckInstance.service_check.type === 'number' ? 'text' : serviceCheckInstance.service_check.type"
                                :inputmode="serviceCheckInstance.service_check.type === 'number' ? 'decimal' : null"
                                v-model="form.description"
                                class="block w-full rounded-md bg-white dark:bg-slate-800 px-3 py-1.5 text-base text-gray-900 dark:text-slate-100 outline-1 -outline-offset-1 outline-gray-300 dark:outline-slate-600 placeholder:text-gray-400 dark:placeholder:text-slate-500 focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600 sm:text-sm/6"
                                :placeholder="`Vul een ${serviceCheckInstance.service_check.type === 'number' ? 'getal' : 'tekst'} in`" />
                        </div>
                    </div>
                    <div v-if="readonly"
                        class="absolute inset-0 bg-white/30 dark:bg-slate-800/60 flex items-center justify-center">
                        <LockClosedIcon class="h-7 w-7 text-gray-600 dark:text-slate-400"
                            v-tooltip="'Deze keuring is gesloten, je kunt de keurpunten daarom alleen nog maar bekijken.'" />
                    </div>
                </div>
                <Transition name="fade-slide" v-if="!readonly">
                    <div v-if="show_remarks" class="mt-10">
                        <RemarksComponent :remarkable-type="'App\\Models\\ServiceCheckInstance'"
                            :remarkable-id="serviceCheckInstance.id" :comments="serviceCheckInstance.remarks || []" />
                    </div>
                </Transition>
                <div v-else-if="readonly && (serviceCheckInstance.remarks?.length)" class="mt-4">
                    <ul class="space-y-2 text-xs text-gray-600 dark:text-slate-400 list-disc ml-5">
                        <li v-for="r in serviceCheckInstance.remarks" :key="r.id">
                            <span class="font-medium text-gray-800 dark:text-slate-200">{{ r.user?.name || 'Onbekend'
                                }}:</span>
                            {{ r.content }}
                        </li>
                    </ul>
                </div>
            </div>
            <Cog6ToothIcon v-if="updating"
                class="absolute top-4 right-4 h-6 w-6 text-gray-500 dark:text-slate-400 animate-spin" />
            <CheckIcon v-if="!updating" class="absolute top-4 right-4 h-6 w-6 text-green-500 dark:text-green-400" />
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import { debounce } from 'lodash';
import { CheckIcon, Cog6ToothIcon, LockClosedIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline';
import RemarksComponent from '@/Components/RemarksComponent.vue';

const { serviceCheckInstance } = defineProps({
    serviceCheckInstance: { type: Object, required: true },
    readonly: { type: Boolean, default: false }
});

const updating = ref(false);
const last_sent = ref(null);
const show_remarks = ref(false);
const toggle_remarks = () => { show_remarks.value = !show_remarks.value; };

const form = useForm({
    values: serviceCheckInstance.service_check.type === 'radio'
        ? (serviceCheckInstance.values[0]?.id ?? null)
        : serviceCheckInstance.values.map(v => v.id),
    description: serviceCheckInstance.description ?? '',
    switch_state: serviceCheckInstance.switch_state,
});

const updateInstance = debounce(() => {
    const type = serviceCheckInstance.service_check.type;
    if (type === 'boolean') {
        const current = form.switch_state;
        if (last_sent.value === current) {
            return;
        }
        last_sent.value = current;
    }
    updating.value = true;
    if (form.description !== null && typeof form.description === 'string') {
        form.description = form.description.trim();
    }
    form.put(`/servicecheckinstances/${serviceCheckInstance.id}`, {
        preserveScroll: true,
        onFinish: () => { updating.value = false; }
    });
}, 500);

watch(
    () => [form.values, form.description, form.switch_state],
    () => {
        if (serviceCheckInstance.service_check.type === 'boolean') {
            updateInstance();
            return;
        }
        if (!updating.value) {
            updateInstance();
        }
    }
)
</script>