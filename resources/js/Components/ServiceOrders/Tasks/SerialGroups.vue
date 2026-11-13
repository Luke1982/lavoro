<template>
    <div class="space-y-4">
        <div v-for="group in groups" :key="group.key">
            <p v-if="group.label" class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">
                {{ group.label }}
                <span class="text-xs font-normal text-gray-400 ml-1">
                    ({{ group.rows.filter(r => r.asset_id).length }}/{{ group.expected }})
                </span>
            </p>
            <div v-if="!group.requires_serial" class="flex items-center gap-2">
                <p class="flex-1 text-xs text-gray-500 dark:text-slate-400">
                    Dit onderdeel heeft geen serienummer;
                    {{ group.rows.filter(r => !r.asset_id).length }} van
                    {{ group.expected }} nog te registreren.
                </p>
                <button v-if="!readonly && group.rows.some(rowIsDirty)" type="button" :disabled="submitting"
                    @click="$emit('save', group.rows)"
                    class="flex-none px-2.5 py-1 rounded-md bg-lavoro-green text-xs font-semibold text-gray-900 hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                    Registreren
                </button>
                <span v-else class="flex-none p-1.5" v-tooltip="'Volledig geregistreerd'">
                    <CheckIcon class="w-4 h-4 text-green-500" />
                </span>
            </div>
            <div v-else class="space-y-2">
                <SerialRow v-for="(row, i) in group.rows" :key="row.asset_id ?? `new-${group.key}-${i}`"
                    :model-value="row.serial_number" :number="numberFrom + i" :error="row.error"
                    :dirty="rowIsDirty(row)" :saved="!!row.asset_id" :submitting="submitting" :readonly="readonly"
                    @update:model-value="value => $emit('type', row, value)" @save="$emit('save', [row])" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { Check as CheckIcon } from '@lucide/vue'
import SerialRow from '@/Components/ServiceOrders/Tasks/SerialRow.vue'
import { rowIsDirty } from '@/Components/ServiceOrders/Tasks/taskSerials.js'

/** The machines of one or more products, each with its serial or its registration. */
defineProps({
    groups: { type: Array, required: true },
    submitting: { type: Boolean, default: false },
    numberFrom: { type: Number, default: 1 },
    /** Shown but not changed: a closed werkbon, or no right to work the task. */
    readonly: { type: Boolean, default: false },
})

defineEmits(['save', 'type'])
</script>
