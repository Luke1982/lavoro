<template>
    <div>
        <div v-if="materials.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">
            Geen materialen geregistreerd op deze werkbon.
        </div>

        <template v-else>
            <!-- Voorziene materialen -->
            <h4 class="text-sm font-semibold text-gray-700 dark:text-slate-200 mb-2">Voorziene materialen</h4>
            <table class="w-full text-sm mb-6">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-slate-700 text-xs font-bold uppercase tracking-wide text-slate-400">
                        <th class="text-left pb-2 pr-4">Materiaal</th>
                        <th class="text-right pb-2 pr-4">Aantal</th>
                        <th class="text-right pb-2 pr-4">Prijs/stuk</th>
                        <th class="text-right pb-2">Totaal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="material in forseenMaterials" :key="material.pivot.id"
                        class="border-b border-gray-100 dark:border-slate-800">
                        <td class="py-2 pr-4">
                            <span class="font-medium text-gray-800 dark:text-slate-100">{{ material.name }}</span>
                            <span v-if="material.code" class="block text-xs text-gray-400 dark:text-slate-500">{{ material.code }}</span>
                        </td>
                        <td class="py-2 pr-4 text-right text-gray-700 dark:text-slate-300 whitespace-nowrap">
                            {{ material.pivot.quantity }}{{ material.usage_unit ? ' ' + material.usage_unit.name : '' }}
                        </td>
                        <td class="py-2 pr-4 text-right text-gray-700 dark:text-slate-300 whitespace-nowrap">
                            {{ nlCurrency(material.price) }}
                        </td>
                        <td class="py-2 text-right font-medium text-gray-900 dark:text-slate-100 whitespace-nowrap">
                            {{ nlCurrency(Number(material.pivot.quantity) * Number(material.price)) }}
                        </td>
                    </tr>
                    <tr v-if="forseenMaterials.length === 0">
                        <td colspan="4" class="py-2 text-gray-400 dark:text-slate-500 italic">Geen voorziene materialen</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 dark:border-slate-600">
                        <td colspan="3" class="pt-2 pr-4 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Subtotaal voorzien</td>
                        <td class="pt-2 text-right font-semibold text-gray-900 dark:text-slate-100 whitespace-nowrap">{{ nlCurrency(forseenSubtotal) }}</td>
                    </tr>
                </tfoot>
            </table>

            <!-- Onvoorziene materialen -->
            <template v-if="unforseenMaterials.length > 0">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-slate-200 mb-2">Onvoorziene materialen</h4>
                <table class="w-full text-sm mb-6">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-slate-700 text-xs font-bold uppercase tracking-wide text-slate-400">
                            <th class="text-left pb-2 pr-4">Materiaal</th>
                            <th class="text-right pb-2 pr-4">Aantal</th>
                            <th class="text-right pb-2 pr-4">Prijs/stuk</th>
                            <th class="text-right pb-2">Totaal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="material in unforseenMaterials" :key="material.pivot.id"
                            class="border-b border-gray-100 dark:border-slate-800">
                            <td class="py-2 pr-4">
                                <span class="font-medium text-gray-800 dark:text-slate-100">{{ material.name }}</span>
                                <span v-if="material.code" class="block text-xs text-gray-400 dark:text-slate-500">{{ material.code }}</span>
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-700 dark:text-slate-300 whitespace-nowrap">
                                {{ material.pivot.quantity }}{{ material.usage_unit ? ' ' + material.usage_unit.name : '' }}
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-700 dark:text-slate-300 whitespace-nowrap">
                                {{ nlCurrency(material.price) }}
                            </td>
                            <td class="py-2 text-right font-medium text-gray-900 dark:text-slate-100 whitespace-nowrap">
                                {{ nlCurrency(Number(material.pivot.quantity) * Number(material.price)) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 dark:border-slate-600">
                            <td colspan="3" class="pt-2 pr-4 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Subtotaal onvoorzien</td>
                            <td class="pt-2 text-right font-semibold text-gray-900 dark:text-slate-100 whitespace-nowrap">{{ nlCurrency(unforseenSubtotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </template>

            <!-- Aggregate box -->
            <div class="rounded-lavoro-sm border border-gray-200 dark:border-slate-700 bg-gray-50/60 dark:bg-slate-800/40 p-4 text-sm">
                <div v-if="unforseenMaterials.length > 0" class="space-y-1.5 mb-3">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-slate-400">Voorzien</span>
                        <span class="text-gray-700 dark:text-slate-300">{{ nlCurrency(forseenSubtotal) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-slate-400">Onvoorzien</span>
                        <span class="text-gray-700 dark:text-slate-300">{{ nlCurrency(unforseenSubtotal) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-gray-200 dark:border-slate-700 pt-1.5">
                        <span class="text-gray-500 dark:text-slate-400">Subtotaal</span>
                        <span class="text-gray-700 dark:text-slate-300">{{ nlCurrency(combinedSubtotal) }}</span>
                    </div>
                </div>
                <div v-else class="flex justify-between mb-1.5">
                    <span class="text-gray-500 dark:text-slate-400">Subtotaal</span>
                    <span class="text-gray-700 dark:text-slate-300">{{ nlCurrency(combinedSubtotal) }}</span>
                </div>
                <div class="flex justify-between mb-1.5">
                    <span class="text-gray-500 dark:text-slate-400">BTW (21%)</span>
                    <span class="text-gray-700 dark:text-slate-300">{{ nlCurrency(vat) }}</span>
                </div>
                <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-slate-700">
                    <span class="font-semibold text-gray-900 dark:text-slate-100">Totaal incl. BTW</span>
                    <span class="font-semibold text-gray-900 dark:text-slate-100">{{ nlCurrency(total) }}</span>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { nlCurrency } from '@/Utilities/Utilities.js'

const props = defineProps({
    materials: {
        type: Array,
        default: () => [],
    },
})

const forseenMaterials = computed(() => props.materials.filter(m => !m.pivot.unforseen))
const unforseenMaterials = computed(() => props.materials.filter(m => !!m.pivot.unforseen))

const forseenSubtotal = computed(() =>
    forseenMaterials.value.reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
const unforseenSubtotal = computed(() =>
    unforseenMaterials.value.reduce((sum, m) => sum + Number(m.pivot.quantity) * Number(m.price), 0)
)
const combinedSubtotal = computed(() => forseenSubtotal.value + unforseenSubtotal.value)
const vat = computed(() => combinedSubtotal.value * 0.21)
const total = computed(() => combinedSubtotal.value + vat.value)
</script>
