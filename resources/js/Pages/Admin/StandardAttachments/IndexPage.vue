<template>
    <div class="p-6 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Standaard bijlagen</h1>
        <FileUploadComponent :items="standardAttachments" upload-url="/standard-attachments"
            delete-url-base="/standard-attachments" name-placeholder="Naam van de bijlage"
            empty-message="Nog geen standaard bijlagen."
            :confirm-delete-message="(item) => `Bijlage &quot;${item.name}&quot; verwijderen?`" />
    </div>
</template>

<script setup>
import FileUploadComponent from '@/Components/UI/FileUploadComponent.vue'

defineProps({
    standardAttachments: { type: Array, required: true },
})
</script>
