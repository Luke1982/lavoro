<template>
    <BoxComponent extra-classes="flex flex-col" padding="flex-1 min-h-0 overflow-y-auto">
        <div class="flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between px-3 pt-2 pb-2">
                <span class="text-xs text-lavoro-dark font-medium">Groepen</span>
                <button
                    class="flex items-center gap-1 text-xs text-lavoro-blue hover:underline"
                    @click="startNewGroup">
                    <PlusIcon class="size-3" />
                    Toevoegen
                </button>
            </div>

            <!-- New group inline form -->
            <div v-if="newGroup !== null"
                class="flex items-center gap-2 px-3 pb-2 border-b border-b-lavoro-gray-150">
                <input
                    ref="newNameInput"
                    v-model="newGroup.name"
                    type="text"
                    placeholder="Groepsnaam"
                    class="flex-1 text-xs border border-gray-300 dark:border-slate-700 rounded px-2 py-1 dark:bg-slate-800 focus:outline-none focus:border-lavoro-blue"
                    @keydown.enter="confirmNewGroup"
                    @keydown.escape="newGroup = null" />
                <input
                    type="color"
                    v-model="newGroup.color"
                    class="h-6 w-6 cursor-pointer rounded border-0 p-0 bg-transparent" />
                <button
                    class="text-xs font-semibold text-lavoro-blue"
                    @click="confirmNewGroup">OK</button>
                <button
                    class="text-xs text-gray-400 hover:text-gray-600"
                    @click="newGroup = null">✕</button>
            </div>

            <!-- Groups -->
            <div
                v-for="group in planGroups"
                :key="group.id"
                class="border-b border-b-lavoro-gray-150"
                @dragover.prevent="onDragOver($event, group.id)"
                @dragleave.self="dropTargetId = undefined"
                @drop.prevent="onDrop($event, group.id)">

                <!-- Group header row -->
                <div
                    class="flex items-center gap-2 px-3 py-2 select-none transition-colors"
                    :class="dropTargetId === group.id && activeType === 'user' ? 'bg-lavoro-lightblue' : ''"
                    draggable="true"
                    @dragstart="onGroupDragStart($event, group)"
                    @dragend="resetDrag">

                    <Bars3Icon class="size-3.5 text-gray-400 cursor-grab shrink-0" />

                    <!-- Color swatch / picker -->
                    <label class="cursor-pointer shrink-0 relative">
                        <span
                            class="block h-3.5 w-3.5 rounded-sm ring-1 ring-black/10"
                            :style="{ background: group.color }" />
                        <input
                            type="color"
                            :value="group.color"
                            class="absolute inset-0 opacity-0 w-full h-full cursor-pointer"
                            @change="e => $emit('group-updated', group.id, { color: e.target.value })" />
                    </label>

                    <!-- Editable name -->
                    <input
                        v-if="editingGroupId === group.id"
                        :ref="el => { if (el) editNameInputEl = el }"
                        v-model="editingName"
                        type="text"
                        class="flex-1 text-xs border border-lavoro-blue rounded px-1 py-0.5 dark:bg-slate-800 focus:outline-none"
                        @blur="confirmEditName(group)"
                        @keydown.enter="confirmEditName(group)"
                        @keydown.escape="editingGroupId = null" />
                    <span
                        v-else
                        class="flex-1 text-xs font-medium text-lavoro-dark truncate cursor-pointer hover:text-lavoro-blue"
                        @click="startEditName(group)">{{ group.name }}</span>

                    <span class="text-[10px] text-gray-400 shrink-0">{{ usersInGroup(group.id).length }}</span>

                    <button
                        class="text-gray-400 hover:text-red-500 shrink-0"
                        @click="deleteGroup(group)">
                        <TrashIcon class="size-3.5" />
                    </button>

                    <button
                        class="text-gray-400 shrink-0"
                        @click="toggleCollapse(group.id)">
                        <ChevronDownIcon v-if="!collapsedGroups.has(group.id)" class="size-3.5" />
                        <ChevronRightIcon v-else class="size-3.5" />
                    </button>
                </div>

                <!-- Users in group -->
                <div v-if="!collapsedGroups.has(group.id)">
                    <div
                        v-for="user in usersInGroup(group.id)"
                        :key="user.id"
                        class="flex items-center gap-2 px-3 py-1.5 border-t border-t-lavoro-gray-150 cursor-grab"
                        draggable="true"
                        @dragstart="onUserDragStart($event, user, group.id)"
                        @dragend="resetDrag">
                        <div class="h-6 w-6 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 overflow-hidden">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full" :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>
                        <span class="flex-1 text-xs truncate text-lavoro-dark">{{ user.name }}</span>
                        <input
                            type="checkbox"
                            :checked="user.plannable"
                            class="h-3.5 w-3.5 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer"
                            :title="user.plannable ? 'Inplanbaar — klik om uit te zetten' : 'Niet inplanbaar — klik om aan te zetten'"
                            @change="$emit('plannable-toggled', user.id, $event.target.checked)" />
                    </div>
                    <div v-if="!usersInGroup(group.id).length"
                        class="px-3 py-1.5 text-[10px] text-gray-400 italic">
                        Sleep een monteur hierheen
                    </div>
                </div>
            </div>

            <!-- Geen groep section -->
            <div
                class="border-b border-b-lavoro-gray-150"
                @dragover.prevent="onDragOver($event, null)"
                @dragleave.self="dropTargetId = undefined"
                @drop.prevent="onDrop($event, null)">

                <div
                    class="flex items-center gap-2 px-3 py-2 select-none transition-colors"
                    :class="dropTargetId === null && activeType === 'user' ? 'bg-gray-50 dark:bg-slate-800' : ''">
                    <span class="h-3.5 w-3.5 rounded-sm bg-gray-300 dark:bg-slate-600 shrink-0" />
                    <span class="flex-1 text-xs font-medium text-gray-400 truncate">Geen groep</span>
                    <span class="text-[10px] text-gray-400 shrink-0">{{ ungroupedUsers.length }}</span>
                    <button class="text-gray-400 shrink-0" @click="toggleCollapse('ungrouped')">
                        <ChevronDownIcon v-if="!collapsedGroups.has('ungrouped')" class="size-3.5" />
                        <ChevronRightIcon v-else class="size-3.5" />
                    </button>
                </div>

                <div v-if="!collapsedGroups.has('ungrouped')">
                    <div
                        v-for="user in ungroupedUsers"
                        :key="user.id"
                        class="flex items-center gap-2 px-3 py-1.5 border-t border-t-lavoro-gray-150 cursor-grab"
                        draggable="true"
                        @dragstart="onUserDragStart($event, user, null)"
                        @dragend="resetDrag">
                        <div class="h-6 w-6 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 overflow-hidden">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full" :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>
                        <span class="flex-1 text-xs truncate text-lavoro-dark">{{ user.name }}</span>
                        <input
                            type="checkbox"
                            :checked="user.plannable"
                            class="h-3.5 w-3.5 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer"
                            :title="user.plannable ? 'Inplanbaar — klik om uit te zetten' : 'Niet inplanbaar — klik om aan te zetten'"
                            @change="$emit('plannable-toggled', user.id, $event.target.checked)" />
                    </div>
                    <div v-if="!ungroupedUsers.length"
                        class="px-3 py-1.5 text-[10px] text-gray-400 italic">
                        Alle monteurs zijn ingedeeld
                    </div>
                </div>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import {
    PlusIcon, TrashIcon, ChevronDownIcon, ChevronRightIcon, Bars3Icon,
} from '@heroicons/vue/24/outline'
import { initials } from '@/Utilities/Utilities'

const props = defineProps({
    planGroups: { type: Array, default: () => [] },
    allUsers:   { type: Array, default: () => [] },
})

const emit = defineEmits([
    'group-created',
    'group-updated',
    'group-deleted',
    'group-reordered',
    'user-assigned',
    'user-unassigned',
    'plannable-toggled',
])

// UI state
const newGroup        = ref(null)
const newNameInput    = ref(null)
const editingGroupId  = ref(null)
const editingName     = ref('')
const editNameInputEl = ref(null)
const collapsedGroups = ref(new Set())

// Drag state
const activeType         = ref(null)
const dropTargetId       = ref(undefined)
const draggingUserId     = ref(null)
const draggingFromGroupId = ref(null)

// Computed
function usersInGroup(groupId) {
    return [...props.allUsers]
        .filter(u => u.plan_group_id === groupId)
        .sort((a, b) => a.name.localeCompare(b.name))
}

const ungroupedUsers = computed(() =>
    [...props.allUsers]
        .filter(u => u.plan_group_id == null)
        .sort((a, b) => a.name.localeCompare(b.name))
)

// New group
function startNewGroup() {
    newGroup.value = { name: '', color: '#2563ff' }
    nextTick(() => newNameInput.value?.focus())
}

function confirmNewGroup() {
    if (!newGroup.value?.name?.trim()) { newGroup.value = null; return }
    emit('group-created', { name: newGroup.value.name.trim(), color: newGroup.value.color })
    newGroup.value = null
}

// Edit name
function startEditName(group) {
    editingGroupId.value = group.id
    editingName.value = group.name
    nextTick(() => {
        editNameInputEl.value?.focus()
        editNameInputEl.value?.select()
    })
}

function confirmEditName(group) {
    const trimmed = editingName.value.trim()
    if (trimmed && trimmed !== group.name) {
        emit('group-updated', group.id, { name: trimmed })
    }
    editingGroupId.value = null
}

// Delete
function deleteGroup(group) {
    const count = usersInGroup(group.id).length
    if (count > 0 && !window.confirm(`Groep "${group.name}" heeft ${count} monteur(s). Weet je zeker dat je wilt verwijderen?`)) return
    emit('group-deleted', group.id)
}

// Collapse
function toggleCollapse(key) {
    const next = new Set(collapsedGroups.value)
    next.has(key) ? next.delete(key) : next.add(key)
    collapsedGroups.value = next
}

// Drag — groups (reorder)
function onGroupDragStart(e, group) {
    activeType.value = 'group'
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('application/x-plan-group', String(group.id))
}

// Drag — users (assignment)
function onUserDragStart(e, user, fromGroupId) {
    activeType.value = 'user'
    draggingUserId.value = user.id
    draggingFromGroupId.value = fromGroupId
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('application/x-plan-group-user', JSON.stringify({ userId: user.id, fromGroupId }))
    e.stopPropagation()
}

function resetDrag() {
    activeType.value = null
    dropTargetId.value = undefined
    draggingUserId.value = null
    draggingFromGroupId.value = null
}

// Drop target hover
function onDragOver(e, targetId) {
    if (e.dataTransfer.types.includes('application/x-plan-group-user')) {
        e.dataTransfer.dropEffect = 'move'
        dropTargetId.value = targetId
    } else if (e.dataTransfer.types.includes('application/x-plan-group')) {
        e.dataTransfer.dropEffect = 'move'
        dropTargetId.value = targetId
    }
}

// Drop
function onDrop(e, targetId) {
    dropTargetId.value = undefined

    if (e.dataTransfer.types.includes('application/x-plan-group-user')) {
        let payload
        try { payload = JSON.parse(e.dataTransfer.getData('application/x-plan-group-user')) } catch { return }
        const { userId, fromGroupId } = payload
        if (targetId === fromGroupId) return
        if (targetId == null) {
            emit('user-unassigned', userId)
        } else {
            emit('user-assigned', targetId, userId)
        }
        return
    }

    if (e.dataTransfer.types.includes('application/x-plan-group')) {
        const draggedId = parseInt(e.dataTransfer.getData('application/x-plan-group'), 10)
        if (!draggedId || draggedId === targetId || targetId == null) return
        const ids = props.planGroups.map(g => g.id)
        const fromIdx = ids.indexOf(draggedId)
        const toIdx = ids.indexOf(targetId)
        if (fromIdx === -1 || toIdx === -1) return
        const newIds = [...ids]
        newIds.splice(fromIdx, 1)
        newIds.splice(toIdx, 0, draggedId)
        emit('group-reordered', newIds)
    }
}
</script>
