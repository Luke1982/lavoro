<template>
    <div>
        <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">
            Onderdelen met een vrij aantal
        </label>
        <p class="text-xs text-gray-500 dark:text-slate-400 mb-2">
            Vul in hoeveel er van deze onderdelen in één bundel gaan.
        </p>
        <div class="space-y-2">
            <div v-for="part in parts" :key="part.productable_id" class="flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-800 dark:text-slate-200 truncate">{{ part.name }}</p>
                    <p class="text-xs text-gray-400">
                        <span v-if="part.is_required">Verplicht</span>
                        <span v-else>Optioneel</span>
                        &middot;
                        <span v-if="part.requires_serial">serienummer per stuk</span>
                        <span v-else>geen serienummer</span>
                    </p>
                </div>
                <input type="number" :min="part.is_required ? 1 : 0" max="999"
                    :value="quantities[part.child_product_id] ?? 0"
                    @input="emit('change', part.child_product_id, Number($event.target.value))"
                    class="w-20 rounded-md border-0 py-1.5 px-2 text-sm text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900" />
                <span class="w-16 text-right text-xs text-gray-400">
                    {{ (quantities[part.child_product_id] ?? 0) * Math.max(1, bundleCount || 1) }} totaal
                </span>
            </div>
        </div>
        <p v-if="error" class="text-xs text-red-600 mt-2">{{ error }}</p>
    </div>
</template>

<script setup>
/**
 * The aantallen a bundle leaves open until it is sold — one omvormer with however many
 * panelen this roof takes. Keyed by the part's own product, which is how the werkbon
 * addresses it, and shown alongside the total across all bundles on the taak.
 */
defineProps({
    parts: { type: Array, default: () => [] },
    quantities: { type: Object, required: true },
    bundleCount: { type: Number, default: 1 },
    error: { type: String, default: '' },
})

const emit = defineEmits(['change'])
</script>
