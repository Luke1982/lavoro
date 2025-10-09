<template>
    <div v-if="(hasPermission('document.see') && existing.length > 0) || hasPermission('document.upload')"
        class="w-full mx-auto p-4 bg-white dark:bg-slate-900/70 rounded-lg shadow-sm border border-gray-200 dark:border-slate-800">
        <h3 class="text-lg font-semibold mb-4 dark:text-slate-100">Documenten</h3>
        <ul v-if="existing.length > 0 && hasPermission('document.see')" class="space-y-2 mb-4" v-auto-animate>
            <li v-for="doc in existing" :key="doc.id"
                class="flex items-center justify-between p-2 rounded-md bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-slate-700">
                <a :href="`/storage/${doc.path}`" target="_blank"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 truncate flex-grow mr-4">
                    {{ doc.name }}
                </a>
                <button @click="deleteDocument(doc.id)" v-if="hasPermission('document.delete')"
                    class="text-red-500 hover:text-red-700 dark:hover:text-red-400 p-1 rounded-full"
                    title="Verwijder dit document">
                    <TrashIcon class="h-4 w-4" />
                </button>
            </li>
        </ul>

        <div v-if="hasPermission('document.upload')" @click="openFilePicker" @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop" :class="[
                'flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-lg cursor-pointer transition-colors',
                isDragging ? 'bg-gray-200 border-gray-400 dark:bg-slate-700 dark:border-slate-600' : 'bg-white border-gray-300 dark:bg-slate-800 dark:border-slate-600'
            ]">
            <p class="text-sm text-gray-500 dark:text-slate-400 text-center p-2">Sleep documenten hierheen of klik om te
                selecteren</p>
            <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Max 10MB per bestand</p>
            <input ref="fileInput" type="file" class="hidden" @change="handleFiles" multiple
                accept=".pdf,.odt,.odf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" />
        </div>
        <div v-if="uploadForm.progress" class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700 mt-4">
            <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: uploadForm.progress.percentage + '%' }"></div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { TrashIcon } from '@heroicons/vue/24/solid';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    documentableId: {
        type: Number,
        required: true,
    },
    documentableType: {
        type: String,
        required: true,
    },
    existing: {
        type: Array,
        default: () => [],
    },
});

const fileInput = ref(null);
const isDragging = ref(false);

const uploadForm = useForm({
    documents: [],
    documentable_id: props.documentableId,
    documentable_type: props.documentableType,
});

const deleteForm = useForm({});

const openFilePicker = () => {
    fileInput.value.click();
};

const handleFiles = (event) => {
    uploadDocuments(Array.from(event.target.files));
    event.target.value = null; // Reset file input
};

const handleDrop = (event) => {
    isDragging.value = false;
    uploadDocuments(Array.from(event.dataTransfer.files));
};

const uploadDocuments = (files) => {
    uploadForm.documents = files;
    uploadForm.post('/documents', {
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset('documents');
        },
        onError: (errors) => {
            const errorMessages = Object.values(errors).join('\n');
            alert(`Fout bij uploaden:\n${errorMessages}`);
        }
    });
};

const deleteDocument = (id) => {
    if (confirm('Weet je zeker dat je dit document wilt verwijderen?')) {
        deleteForm.delete(`/documents/${id}`, {
            preserveScroll: true,
        });
    }
};
</script>
