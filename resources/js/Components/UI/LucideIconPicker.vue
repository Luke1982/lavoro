<template>
    <div class="relative" ref="containerRef">
        <button type="button" @click="toggle"
            class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
            <component :is="selectedIcon" v-if="selectedIcon" class="size-4 shrink-0" />
            <span v-if="!modelValue" class="text-gray-400 dark:text-slate-500">Kies icoon</span>
            <span v-else class="text-xs text-gray-500 dark:text-slate-400 font-mono">{{ modelValue }}</span>
            <XIcon v-if="modelValue" class="size-3 text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 ml-1"
                @click.stop="clear" />
        </button>

        <div v-if="open"
            class="absolute left-0 top-full mt-1 z-50 w-80 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg p-3">
            <input v-model="search" ref="searchRef" type="text" placeholder="Zoeken..."
                class="w-full text-sm rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 dark:text-white px-2.5 py-1.5 mb-3 focus:outline-none focus:ring-2 focus:ring-indigo-500" />

            <div v-if="filtered.length"
                class="grid grid-cols-7 gap-1 max-h-56 overflow-y-auto">
                <button v-for="icon in filtered" :key="icon.name" type="button"
                    :title="icon.name"
                    :class="[
                        'flex items-center justify-center p-1.5 rounded hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors',
                        modelValue === icon.name ? 'bg-indigo-100 dark:bg-indigo-800/50 ring-1 ring-indigo-400' : '',
                    ]"
                    @click="select(icon.name)">
                    <component :is="icon.component" class="size-5 text-gray-700 dark:text-slate-300" />
                </button>
            </div>
            <p v-else class="text-sm text-gray-400 dark:text-slate-500 text-center py-4">Geen iconen gevonden</p>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { XIcon } from '@lucide/vue'
import { ICON_MAP } from '@/Utilities/lucideIconMap.js'

const props = defineProps({
    modelValue: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['update:modelValue'])

const ALL_ICONS = Object.entries(ICON_MAP).map(([name, component]) => ({ name, component }))

const open = ref(false)
const search = ref('')
const containerRef = ref(null)
const searchRef = ref(null)

const selectedIcon = computed(() => {
    if (!props.modelValue) return null
    return ALL_ICONS.find(i => i.name === props.modelValue)?.component ?? null
})

const filtered = computed(() => {
    if (!search.value.trim()) return ALL_ICONS
    const q = search.value.toLowerCase()
    return ALL_ICONS.filter(i => i.name.toLowerCase().includes(q))
})

function toggle() {
    open.value = !open.value
    if (open.value) {
        search.value = ''
        nextTick(() => searchRef.value?.focus())
    }
}

function select(name) {
    emit('update:modelValue', name)
    open.value = false
    search.value = ''
}

function clear() {
    emit('update:modelValue', null)
}

function onDocumentClick(e) {
    if (containerRef.value && !containerRef.value.contains(e.target)) {
        open.value = false
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick, true))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick, true))
</script>
