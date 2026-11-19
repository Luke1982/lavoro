<template>
    <div class="mb-4">
        <h4 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-slate-400">Proefberekening</h4>
        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
            Welke machines heeft de voorbeeldklant? Er wordt niets opgeslagen.
        </p>
        <div v-for="(line, index) in sample" :key="index"
            class="mt-2 space-y-2 rounded-md border border-gray-200 p-2 text-sm text-gray-700 dark:border-slate-600 dark:text-slate-300">
            <div class="flex items-center gap-2">
                <div class="w-20">
                    <TextInput v-model.number="line.quantity" type="number" min="0" />
                </div>
                <span>× op adres</span>
                <div class="w-16">
                    <TextInput v-model.number="line.address" type="number" min="1" max="20" />
                </div>
                <span class="flex-1" />
                <button type="button" class="p-1 text-gray-400 hover:text-red-600" title="Weghalen"
                    @click="sample.splice(index, 1)">
                    <XMarkIcon class="size-4" />
                </button>
            </div>
            <MachinePicker v-model:product-type-id="line.product_type_id" v-model:product-id="line.product_id"
                v-bind="pickerProps" all-label="Kies een producttype" stacked />
        </div>
        <button type="button" class="mt-2 text-sm font-medium text-lavoro-blue hover:underline dark:text-blue-400"
            @click="sample.push({ quantity: 1, product_type_id: null, product_id: null, address: sample.at(-1)?.address ?? 1 })">
            + Machines
        </button>
    </div>
</template>

<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import MachinePicker from '@/Components/PriceFormulas/MachinePicker.vue'

/**
 * Made-up machines to try a template's formula on. Machines on the same address
 * number stand together, which is what a combination or a per-address line needs.
 */
const sample = defineModel({ type: Array, required: true })

defineProps({
    pickerProps: { type: Object, default: () => ({}) },
})
</script>
