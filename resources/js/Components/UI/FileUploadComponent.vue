<template>
    <div>
        <form @submit.prevent="upload"
            class="flex flex-col gap-3 mb-4 p-4 rounded-lg border border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-900">
            <TextInput v-model="name" :label="nameLabel" type="text" :placeholder="namePlaceholder" />
            <div>
                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Bestand</label>
                <input ref="fileInput" type="file" @change="onFileSelected"
                    class="block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 dark:file:bg-slate-700 file:text-sm file:font-medium hover:file:bg-gray-200 cursor-pointer" />
            </div>
            <div>
                <button type="submit" :disabled="!name || !file || uploading"
                    class="px-4 py-2 rounded-md bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ uploading ? 'Uploaden...' : 'Uploaden' }}
                </button>
            </div>
        </form>

        <ul class="divide-y divide-gray-100 dark:divide-slate-700 rounded-lg border border-gray-100 dark:border-slate-700">
            <li v-for="item in items" :key="item.id" class="flex items-center justify-between px-4 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ item.name }}</p>
                    <p class="text-xs text-gray-400 truncate">{{ item.original_filename }}</p>
                </div>
                <button type="button" @click="remove(item)" class="text-xs text-red-600 hover:underline shrink-0 ml-3">
                    Verwijderen
                </button>
            </li>
            <li v-if="items.length === 0" class="px-4 py-3 text-sm text-gray-400">
                {{ emptyMessage }}
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import TextInput from '@/Components/UI/TextInput.vue'

const props = defineProps({
    items: { type: Array, required: true },
    uploadUrl: { type: String, required: true },
    deleteUrlBase: { type: String, required: true },
    nameLabel: { type: String, default: 'Naam' },
    namePlaceholder: { type: String, default: '' },
    emptyMessage: { type: String, default: 'Nog geen bestanden.' },
    confirmDeleteMessage: {
        type: Function,
        default: (item) => `"${item.name}" verwijderen?`,
    },
})

const name = ref('')
const file = ref(null)
const fileInput = ref(null)
const uploading = ref(false)

function onFileSelected(event) {
    file.value = event.target.files[0] || null
}

function upload() {
    if (!name.value || !file.value) return
    uploading.value = true
    const form = new FormData()
    form.append('name', name.value)
    form.append('file', file.value)
    router.post(props.uploadUrl, form, {
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false
            name.value = ''
            file.value = null
            if (fileInput.value) fileInput.value.value = ''
        },
    })
}

function remove(item) {
    if (!confirm(props.confirmDeleteMessage(item))) return
    router.delete(`${props.deleteUrlBase}/${item.id}`, { preserveScroll: true })
}
</script>
