<template>
    <div class="space-y-4">
        <div
            class="p-4 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800 dark:text-white">
            <IndexHeaderComponent title="Rollen" subtitle="Beheer rollen en koppel gebruikers en permissies"
                :paginator="null" add-label="Nieuwe rol" @add="() => roleFormRef?.show()" />
        </div>

        <div class="mb-2">
            <CreateRecordForm ref="roleFormRef" external-trigger action="/roles" :fields="createFields"
                submit-label="Toevoegen" />
        </div>

        <BoxComponent padding="px-0 py-0">
            <EditableGridComponent :headers="headers" :items="innerRoles" @update="onCellUpdate"
                />
        </BoxComponent>
    </div>

</template>

<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    roles: { type: Array, required: true },
    allUsers: { type: Array, required: true },
    allPermissions: { type: Array, required: true },
})

const createFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const roleFormRef = ref(null)

const headers = [
    { key: 'name', label: 'Rol', fieldtype: 'text', width: 25 },
    { key: 'user_ids', label: 'Gebruikers', fieldtype: 'combobox', multiple: true, width: 35, combovalues: props.allUsers },
    { key: 'permission_ids', label: 'Permissies', fieldtype: 'combobox', multiple: true, width: 40, combovalues: props.allPermissions },
]

const innerRoles = computed(() => (props.roles || []).map(r => ({
    id: r.id,
    name: r.name,
    user_ids: Array.isArray(r.users) ? r.users.map(u => u.id) : [],
    permission_ids: Array.isArray(r.permissions) ? r.permissions.map(p => p.id) : [],
})))

const updateForm = useForm({ user_ids: [], permission_ids: [] })

function onCellUpdate({ item, key }) {
    // Only handle assignment columns here; ignore name edits for now
    if (key !== 'user_ids' && key !== 'permission_ids') return
    updateForm.transform(() => ({
        user_ids: item.user_ids,
        permission_ids: item.permission_ids,
    })).patch(`/roles/${item.id}`, { preserveScroll: true })
}
</script>
