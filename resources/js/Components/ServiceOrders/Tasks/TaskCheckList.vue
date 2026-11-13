<template>
    <ul class="space-y-1.5">
        <template v-for="(check, i) in checks" :key="check.id">
            <li v-if="headings[i]" class="pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                {{ headings[i] }}
            </li>
            <li class="flex items-center gap-3 rounded-lavoro-sm border px-3 py-2" :class="check.is_done
                ? 'border-green-200 bg-green-50/60 dark:border-green-900 dark:bg-green-950/30'
                : 'border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-900'">
                <CheckboxComponent :key="`${check.id}-${reverts[check.id] ?? 0}`" :model-value="check.is_done"
                    :disabled="disabled || pending.has(check.id)" @update:model-value="value => $emit('tick', check, value)" />
                <span class="flex-1 min-w-0 text-sm text-gray-800 dark:text-slate-200">
                    {{ check.title }}<span v-if="check.is_required" class="ml-0.5 text-red-500"
                        v-tooltip="'Verplicht'">*</span>
                </span>
                <span v-if="check.is_done && check.checked_at" class="flex-none text-xs text-gray-500 dark:text-slate-400"
                    v-tooltip="check.checked_by?.name ?? ''">
                    {{ nlTime(check.checked_at) }}
                </span>
            </li>
        </template>
    </ul>
</template>

<script setup>
import { computed } from 'vue'
import CheckboxComponent from '@/Components/UI/AnimatedCheckbox.vue'
import { nlTime } from '@/Utilities/Utilities'

/**
 * The checks of one machine, or of the whole task, under the heading of the
 * checklist they came from. A star marks what has to be ticked before the task
 * can be completed; a ticked check shows the time it was ticked.
 */
const props = defineProps({
    checks: { type: Array, required: true },
    pending: { type: Set, required: true },
    reverts: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
})

defineEmits(['tick'])

/** A heading wherever the checklist changes; checks after a checklist that came with none read as "Overige checks". */
const headings = computed(() => props.checks.map((check, i) => {
    const previous = props.checks[i - 1]?.checklist_title

    if (i > 0 && check.checklist_title === previous) return null

    return check.checklist_title ?? (i > 0 ? 'Overige checks' : null)
}))
</script>
