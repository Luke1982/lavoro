<template>
    <div class="space-y-4">
        <IndexHeaderComponent title="Rollen" subtitle="Beheer rollen en koppel gebruikers en permissies"
            :paginator="null" add-label="Nieuwe rol" @add="() => roleFormRef?.show()" />

        <div class="mb-2">
            <CreateRecordForm ref="roleFormRef" external-trigger action="/roles" :fields="createFields"
                submit-label="Toevoegen" />
        </div>

        <BoxComponent padding="px-0 py-0">
            <div v-if="localRoles.length">
                <div
                    class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                    <div class="col-span-3">Rol</div>
                    <div class="col-span-4">Gebruikers</div>
                    <div class="col-span-5">Permissies</div>
                </div>
                <div v-for="role in localRoles" :key="role.id"
                    class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-start">
                    <div class="col-span-3 pr-4">
                        <EditableTextField v-model="role.name" :decoration="false"
                            @update="(val) => patchRole(role.id, { name: val })" />
                    </div>
                    <div class="col-span-4 pr-4">
                        <EditableTextField v-model="role.user_ids" type="combobox" :options="allUsers" :multiple="true"
                            :decoration="false"
                            @update="(val) => patchRole(role.id, { user_ids: val, permission_ids: role.permission_ids })">
                            <template #display>
                                <span class="inline-flex flex-wrap gap-1">
                                    <BadgeComponent v-for="uid in role.user_ids" :key="uid" color="blue"
                                        :has-dot="false">
                                        {{allUsers.find(u => u.id === uid)?.name}}
                                    </BadgeComponent>
                                    <span v-if="!role.user_ids.length"
                                        class="text-gray-400 text-xs font-normal italic">Geen gebruikers</span>
                                </span>
                            </template>
                        </EditableTextField>
                    </div>
                    <div class="col-span-5">
                        <EditableTextField v-model="role.permission_ids" type="combobox" :options="allPermissions"
                            :multiple="true" :decoration="false"
                            @update="(val) => patchRole(role.id, { user_ids: role.user_ids, permission_ids: val })">
                            <template #display>
                                <span class="flex flex-col gap-2">
                                    <template v-for="group in groupedPermissions(role.permission_ids)"
                                        :key="group.resource">
                                        <span class="flex flex-col gap-1">
                                            <span class="text-xs font-bold text-gray-600">{{ group.resource }}</span>
                                            <span class="flex flex-wrap gap-1">
                                                <BadgeComponent v-for="perm in group.permissions" :key="perm.id"
                                                    color="green" :has-dot="false">
                                                    {{ perm.label }}
                                                </BadgeComponent>
                                            </span>
                                        </span>
                                    </template>
                                    <span v-if="!role.permission_ids.length"
                                        class="text-gray-400 text-xs font-normal italic">Geen permissies</span>
                                </span>
                            </template>
                        </EditableTextField>
                    </div>
                </div>
            </div>
            <div v-else class="p-6 text-center text-gray-400 text-sm">Geen rollen gevonden</div>
        </BoxComponent>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'

const props = defineProps({
    roles: { type: Array, required: true },
    allUsers: { type: Array, required: true },
    allPermissions: { type: Array, required: true },
})

const createFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const roleFormRef = ref(null)

function buildLocalRoles(roles) {
    return (roles || []).map(r => ({
        id: r.id,
        name: r.name,
        user_ids: Array.isArray(r.users) ? r.users.map(u => u.id) : [],
        permission_ids: Array.isArray(r.permissions) ? r.permissions.map(p => p.id) : [],
    }))
}

const localRoles = ref(buildLocalRoles(props.roles))

watch(() => props.roles, (newRoles) => {
    localRoles.value = buildLocalRoles(newRoles)
})

function patchRole(role_id, data) {
    router.patch(`/roles/${role_id}`, data, { preserveScroll: true, preserveState: true })
}

function groupedPermissions(permission_ids) {
    const groups = {}
    for (const id of permission_ids) {
        const perm = props.allPermissions.find(p => p.id === id)
        if (!perm) continue
        const dot = perm.key.indexOf('.')
        const resource = dot === -1 ? perm.key : perm.key.slice(0, dot)
        if (!groups[resource]) groups[resource] = []
        groups[resource].push({ id: perm.id, label: perm.name })
    }
    return Object.keys(groups)
        .sort()
        .map(resource => ({
            resource,
            permissions: groups[resource].sort((a, b) => a.label.localeCompare(b.label)),
        }))
}
</script>
