<template>
    <div data-contents>
        <p v-if="heading" class="mb-1 text-[11px] font-bold uppercase tracking-wide opacity-60">{{ heading }}</p>
        <p v-if="!list.length" class="text-xs opacity-60">{{ emptyText }}</p>
        <draggable v-model="list" handle=".grip" :animation="150" class="space-y-1" @end="reorder">
            <div v-for="item in list" :key="item.linkId"
                class="flex items-center gap-2 rounded bg-white/70 px-2 py-1 text-xs text-gray-800 dark:bg-slate-900/60 dark:text-slate-200">
                <GripVerticalIcon v-if="!readonly" class="grip size-3.5 flex-none cursor-move opacity-50" />
                <span class="min-w-0 flex-1 truncate">{{ item.title }}</span>
                <button type="button" :disabled="readonly" @click="$emit('set-required', item, !item.required)"
                    v-tooltip="item.required ? 'Klik om optioneel te maken' : 'Klik om verplicht te maken'" :class="[
                        'flex-none rounded-full px-1.5 py-0.5 text-[10px] font-bold',
                        item.required
                            ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900'
                            : 'border border-current opacity-70',
                    ]">
                    {{ item.required ? 'verplicht' : 'optioneel' }}
                </button>
            </div>
        </draggable>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import { GripVertical as GripVerticalIcon } from '@lucide/vue'

/**
 * What a checklist or task holds, in the order the mechanic will see it. Drag a
 * row by its grip to move it; click its pill to make it required or optional.
 */
const props = defineProps({
    items: { type: Array, required: true },
    heading: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
})

const emit = defineEmits(['reorder', 'set-required'])

/**
 * Only the order is kept here, and only replaced when the server's order really
 * changed: a redraw while a drop saves does not undo it, and titles and pills
 * always come fresh from the props.
 */
const order = ref(props.items.map(item => item.linkId))

watch(() => props.items.map(item => item.linkId).join(), () => { order.value = props.items.map(item => item.linkId) })

const list = computed({
    get: () => order.value.map(id => props.items.find(item => item.linkId === id)).filter(Boolean),
    set: items => { order.value = items.map(item => item.linkId) },
})

const emptyText = computed(() => (props.heading
    ? `Nog geen ${props.heading.toLowerCase()}.`
    : 'Nog geen checks. Sleep er een check op.'))

function reorder() {
    emit('reorder', order.value)
}
</script>
