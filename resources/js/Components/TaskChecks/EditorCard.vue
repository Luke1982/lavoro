<template>
    <div class="relative rounded-lavoro-sm border px-3 py-2 shadow-sm transition-opacity"
        :draggable="draggable ? 'true' : 'false'" :class="[
        tones[kind],
        draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        selected ? 'ring-2 ring-lavoro-blue ring-offset-1 dark:ring-offset-slate-900' : '',
        faded ? 'opacity-35' : '',
        dropActive ? 'ring-2 ring-lavoro-green ring-offset-1' : '',
    ]" @click="$emit('select')">
        <div class="flex items-center gap-1.5">
            <button v-if="openable" type="button" class="-ml-1 rounded p-0.5 hover:bg-black/5 dark:hover:bg-white/10"
                :aria-label="open ? 'Inklappen' : 'Openklappen'" @click.stop="$emit('toggle-open')">
                <ChevronRightIcon class="size-4 transition-transform" :class="open ? 'rotate-90' : ''" />
            </button>
            <span v-if="!renaming" class="min-w-0 flex-1 truncate text-sm font-semibold">{{ title }}</span>
            <input v-else ref="renameInput" v-model="draft" type="text" @click.stop @keydown.enter.prevent="saveRename"
                @keydown.esc.prevent="renaming = false" @blur="saveRename"
                class="min-w-0 flex-1 rounded border-0 px-1.5 py-0.5 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-slate-900 dark:text-white" />
            <div v-if="editable" @click.stop>
                <DropdownMenu placement="bottom-end" width-class="w-44" title="Meer"
                    button-class="rounded p-0.5 text-current opacity-60 hover:opacity-100">
                    <template #button>
                        <EllipsisVerticalIcon class="size-4" />
                    </template>
                    <MenuItem v-slot="{ active }">
                        <button type="button" @click="startRename"
                            :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 dark:text-slate-200', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                            <PencilIcon class="size-4" /> Hernoemen
                        </button>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                        <button type="button" @click="$emit('delete')"
                            :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                            <TrashIcon class="size-4" /> Verwijderen
                        </button>
                    </MenuItem>
                </DropdownMenu>
            </div>
        </div>
        <p v-if="meta" class="mt-0.5 truncate text-xs opacity-70">{{ meta }}</p>
        <div v-if="open" class="mt-2" @click.stop>
            <slot />
        </div>
    </div>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { MenuItem } from '@headlessui/vue'
import { ChevronRight as ChevronRightIcon, EllipsisVertical as EllipsisVerticalIcon, Pencil as PencilIcon, Trash2 as TrashIcon } from '@lucide/vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'

/**
 * A check, a checklist or a task in the editor. Clicking it shows only what it
 * is connected to; clicking it again shows everything. A checklist or task can
 * open to show what it holds, in order.
 */
const props = defineProps({
    kind: { type: String, required: true },
    title: { type: String, required: true },
    meta: { type: String, default: '' },
    selected: { type: Boolean, default: false },
    faded: { type: Boolean, default: false },
    open: { type: Boolean, default: false },
    openable: { type: Boolean, default: false },
    editable: { type: Boolean, default: false },
    dropActive: { type: Boolean, default: false },
    /** Can be dragged onto a card it may be linked to. */
    draggable: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'rename', 'delete', 'toggle-open'])

const tones = {
    check: 'border-slate-300 bg-white text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200',
    checklist: 'border-green-400 bg-green-50 text-green-900 dark:border-green-700 dark:bg-green-950/60 dark:text-green-200',
    task: 'border-blue-400 bg-blue-50 text-blue-900 dark:border-blue-700 dark:bg-blue-950/60 dark:text-blue-200',
}

const renaming = ref(false)
const draft = ref('')
const renameInput = ref(null)

async function startRename() {
    draft.value = props.title
    renaming.value = true
    await nextTick()
    renameInput.value?.focus()
    renameInput.value?.select()
}

function saveRename() {
    if (!renaming.value) return

    renaming.value = false
    const title = draft.value.trim()

    if (title && title !== props.title) emit('rename', title)
}
</script>
