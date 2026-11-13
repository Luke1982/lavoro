<template>
    <div class="flex flex-col gap-4">
        <div v-if="quantity > 1" class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-300">Checks per machine afvinken</p>
                <p class="text-xs text-gray-500 dark:text-slate-400">
                    {{ locked
                        ? 'Er zijn al checks afgevinkt; dit kan niet meer wijzigen.'
                        : `Uit: de checks één keer voor alle ${quantity} machines samen.` }}
                </p>
            </div>
            <SwitchComponent v-model="perUnit" :disabled="locked" />
        </div>
        <ExtraChecksPicker v-model="extras" :checks="checks" :checklists="checklists" />
    </div>
</template>

<script setup>
import ExtraChecksPicker from '@/Components/ServiceOrders/Tasks/ExtraChecksPicker.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

/**
 * The check settings of a werkbon task in the add and edit drawers: per machine
 * or once for all machines (only asked with more than one machine), and the
 * extras picked on top of the template.
 */
defineProps({
    /** How many machines the task covers; 0 without a product. */
    quantity: { type: Number, default: 0 },
    locked: { type: Boolean, default: false },
    checks: { type: Array, default: () => [] },
    checklists: { type: Array, default: () => [] },
})

const perUnit = defineModel('perUnit', { type: Boolean, default: false })
const extras = defineModel('extras', { type: Object, required: true })
</script>
