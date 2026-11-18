<template>
    <div class="relative">
        <button type="button" @click="fileInput?.click()" :disabled="uploading"
            v-tooltip="'Importeer een prijslijst uit Excel'"
            class="cursor-pointer inline-flex items-center gap-x-1.5 px-3 py-3 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 text-xs font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none transition disabled:opacity-50">
            <ArrowUpTrayIcon class="h-5 w-5" />
            <span class="hidden sm:inline">{{ uploading ? 'Bezig…' : 'Importeren' }}</span>
        </button>
        <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv,.ods" class="hidden" @change="upload" />
        <p v-if="error" class="absolute right-0 top-full mt-1 w-64 text-xs text-red-600 dark:text-red-400 text-right">
            {{ error }}
        </p>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { ArrowUpTrayIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    kind: { type: String, required: true },
})

const page = usePage()
const fileInput = ref(null)
const uploading = ref(false)
const error = computed(() => page.props.errors?.file ?? '')

function upload(event) {
    const file = event.target.files?.[0]
    event.target.value = ''

    if (!file) return

    router.post(`/${props.kind}/imports`, { file }, {
        forceFormData: true,
        onStart: () => { uploading.value = true },
        onFinish: () => { uploading.value = false },
    })
}
</script>
