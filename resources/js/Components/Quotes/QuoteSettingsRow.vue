<template>
    <div class="group flex items-center gap-3 px-3 py-2">
        <button v-if="draggable" type="button" class="draghandle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 dark:text-slate-600"
            aria-label="Verslepen">
            <GripVerticalIcon class="size-4" />
        </button>
        <div class="min-w-0 flex-1">
            <input v-model="form.name" @change="save('name')" :aria-label="label" :class="GHOST_FIELD" />
            <p v-if="form.errors.name" class="text-xs text-red-600 dark:text-red-400">{{ form.errors.name }}</p>
        </div>
        <slot />
        <button type="button" @click="remove" v-tooltip="'Verwijderen'"
            class="opacity-0 group-hover:opacity-100 focus:opacity-100 text-gray-400 hover:text-red-600">
            <Trash2Icon class="size-4" />
        </button>
    </div>
</template>

<script setup>
import { router, useForm } from '@inertiajs/vue3'
import { GripVerticalIcon, Trash2Icon } from '@lucide/vue'
import { GHOST_FIELD } from '@/Components/Quotes/quoteColumns'
import { useSaveField } from '@/Components/Quotes/useSaveField'

/**
 * One row in a list under the quote settings, such as a unit, a reason a quote
 * was lost, or a template: renamed where it stands and saved the moment the
 * field is left. Whatever else the row says or switches goes in the slot.
 */
const props = defineProps({
    item: { type: Object, required: true },
    /** Where the row lives on the server, such as /quote-settings/units/3. */
    url: { type: String, required: true },
    label: { type: String, required: true },
    /** What to ask before it goes. */
    confirmText: { type: String, required: true },
    draggable: { type: Boolean, default: true },
})

const form = useForm({ name: props.item.name })

const save = useSaveField(form, () => props.url, () => props.item)

function remove() {
    if (!confirm(props.confirmText)) return
    router.delete(props.url, { preserveScroll: true })
}
</script>
