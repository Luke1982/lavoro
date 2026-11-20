<template>
    <div class="group flex items-center gap-3 px-3 py-2">
        <button type="button" class="draghandle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 dark:text-slate-600"
            aria-label="Verslepen">
            <GripVerticalIcon class="size-4" />
        </button>
        <div class="min-w-0 flex-1">
            <input v-model="form.name" @change="save('name')" aria-label="Naam van de eenheid" :class="GHOST_FIELD" />
            <p v-if="form.errors.name" class="text-xs text-red-600 dark:text-red-400">{{ form.errors.name }}</p>
        </div>
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

/** One unit in the quote settings, renamed where it stands and saved the moment the field is left. */
const props = defineProps({
    unit: { type: Object, required: true },
})

const form = useForm({ name: props.unit.name })

const save = useSaveField(form, () => `/quote-settings/units/${props.unit.id}`, () => props.unit)

function remove() {
    if (!confirm(`De eenheid "${props.unit.name}" verwijderen? Offertes die hem al gebruiken houden hem.`)) return
    router.delete(`/quote-settings/units/${props.unit.id}`, { preserveScroll: true })
}
</script>
