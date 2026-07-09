<template>
    <BoxComponent extra-classes="flex flex-col" padding="flex-1 min-h-0 overflow-y-auto">
        <div class="flex flex-col divide-y divide-lavoro-gray-150">

            <!-- ── GROEPEN ─────────────────────────────────── -->
            <div class="flex flex-col">
                <div class="flex items-center justify-between px-3 pt-2 pb-2">
                    <span class="text-xs font-medium text-lavoro-dark">Groepen</span>
                    <button class="flex items-center gap-1 text-xs text-lavoro-blue hover:underline"
                        @click="startNewGroup">
                        <PlusIcon class="size-3" />
                        Toevoegen
                    </button>
                </div>

                <!-- New group form -->
                <div v-if="newGroup !== null"
                    class="flex items-center gap-2 px-3 pb-2">
                    <input ref="newNameInput" v-model="newGroup.name" type="text" placeholder="Groepsnaam"
                        class="flex-1 text-xs border border-gray-300 dark:border-slate-700 rounded px-2 py-1 dark:bg-slate-800 focus:outline-none focus:border-lavoro-blue"
                        @keydown.enter="confirmNewGroup" @keydown.escape="newGroup = null" />
                    <input type="color" v-model="newGroup.color"
                        class="h-6 w-6 cursor-pointer rounded border-0 p-0 bg-transparent" />
                    <button class="text-xs font-semibold text-lavoro-blue" @click="confirmNewGroup">OK</button>
                    <button class="text-xs text-gray-400 hover:text-gray-600" @click="newGroup = null">✕</button>
                </div>

                <!-- Group rows (drag to reorder) -->
                <div v-if="planGroups.length" class="pb-1">
                    <div
                        v-for="group in planGroups"
                        :key="group.id"
                        class="flex items-center gap-2 px-3 py-1.5 select-none"
                        :class="dropTargetGroupId === group.id ? 'bg-lavoro-lightblue' : ''"
                        draggable="true"
                        @dragstart="onGroupDragStart($event, group)"
                        @dragend="dropTargetGroupId = undefined"
                        @dragover.prevent="dropTargetGroupId = group.id"
                        @dragleave.self="dropTargetGroupId = undefined"
                        @drop.prevent="onGroupDrop($event, group.id)">

                        <Bars3Icon class="size-3.5 text-gray-400 cursor-grab shrink-0" />

                        <!-- Color swatch / picker -->
                        <label class="cursor-pointer shrink-0 relative">
                            <span class="block h-3.5 w-3.5 rounded-sm ring-1 ring-black/10"
                                :style="{ background: group.color }" />
                            <input type="color" :value="group.color"
                                class="absolute inset-0 opacity-0 w-full h-full cursor-pointer"
                                @change="e => $emit('group-updated', group.id, { color: e.target.value })" />
                        </label>

                        <!-- Editable name -->
                        <input v-if="editingGroupId === group.id"
                            :ref="el => { if (el) editNameInputEl = el }"
                            v-model="editingName" type="text"
                            class="flex-1 text-xs border border-lavoro-blue rounded px-1 py-0.5 dark:bg-slate-800 focus:outline-none"
                            @blur="confirmEditName(group)"
                            @keydown.enter="confirmEditName(group)"
                            @keydown.escape="editingGroupId = null" />
                        <span v-else
                            class="flex-1 text-xs font-medium text-lavoro-dark truncate cursor-pointer hover:text-lavoro-blue"
                            @click="startEditName(group)">{{ group.name }}</span>

                        <span class="text-[10px] text-gray-400 shrink-0">{{ usersInGroup(group.id).length }}</span>

                        <button class="text-gray-400 hover:text-red-500 shrink-0" @click="deleteGroup(group)">
                            <TrashIcon class="size-3.5" />
                        </button>
                    </div>
                </div>

                <div v-else class="px-3 pb-2 text-[10px] text-gray-400 italic">
                    Nog geen groepen aangemaakt.
                </div>
            </div>

            <!-- ── MONTEURS ────────────────────────────────── -->
            <div class="flex flex-col">
                <div class="px-3 pt-2 pb-1">
                    <span class="text-xs font-medium text-lavoro-dark">Monteurs</span>
                </div>

                <div v-if="allUsers.length" class="px-3 pb-2">
                    <label class="flex items-center gap-1.5 border border-gray-300 dark:border-slate-700 rounded px-2 py-1 focus-within:border-lavoro-blue">
                        <MagnifyingGlassIcon class="size-3.5 text-gray-400 shrink-0" />
                        <input v-model="userSearchQuery" type="text" placeholder="Zoeken op naam..."
                            class="flex-1 min-w-0 text-xs dark:bg-slate-800 focus:outline-none" />
                    </label>
                </div>

                <div class="flex flex-col" v-auto-animate>
                    <div v-for="user in visibleUsers" :key="user.id"
                        class="flex items-center gap-2 px-3 py-1.5 border-t border-t-lavoro-gray-150">

                        <!-- Avatar -->
                        <div class="h-6 w-6 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 overflow-hidden">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full" :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>

                        <!-- Name -->
                        <span class="flex-1 text-xs truncate text-lavoro-dark min-w-0">{{ user.name }}</span>

                        <!-- Group toggles (plannable only) -->
                        <div v-if="user.plannable && planGroups.length" class="flex items-center gap-0.5 shrink-0">
                            <button
                                v-for="group in planGroups"
                                :key="group.id"
                                class="h-4 w-4 rounded-sm border transition-all"
                                :class="user.plan_group_ids.includes(group.id)
                                    ? 'border-transparent'
                                    : 'border-gray-300 dark:border-slate-600 bg-transparent'"
                                :style="user.plan_group_ids.includes(group.id) ? { background: group.color } : {}"
                                :title="group.name"
                                @click="toggleUserGroup(user, group.id)" />
                        </div>

                        <!-- Plannable checkbox -->
                        <input type="checkbox" :checked="user.plannable"
                            class="h-3.5 w-3.5 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer shrink-0"
                            :title="user.plannable ? 'Inplanbaar — klik om uit te zetten' : 'Niet inplanbaar — klik om aan te zetten'"
                            @change="$emit('plannable-toggled', user.id, $event.target.checked)" />
                    </div>

                    <div v-if="allUsers.length && filteredUsers.length === 0"
                        class="px-3 py-3 text-[10px] text-gray-400 italic">
                        Geen monteurs gevonden voor "{{ userSearchQuery }}".
                    </div>
                </div>

                <button v-if="filteredUsers.length > maxVisibleUsers" type="button"
                    class="flex items-center justify-center gap-1 px-3 py-2 text-xs font-medium text-lavoro-blue hover:underline"
                    @click="isUsersExpanded = !isUsersExpanded">
                    <template v-if="isUsersExpanded">
                        <ChevronUpIcon class="size-3.5" />
                        Toon minder
                    </template>
                    <template v-else>
                        <ChevronDownIcon class="size-3.5" />
                        Toon alle ({{ filteredUsers.length }})
                    </template>
                </button>
            </div>

        </div>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import {
    PlusIcon, TrashIcon, Bars3Icon,
    MagnifyingGlassIcon, ChevronDownIcon, ChevronUpIcon,
} from '@heroicons/vue/24/outline'
import { initials } from '@/Utilities/Utilities'
import { useExpandableFilter } from '@/Composables/useExpandableFilter'

const props = defineProps({
    planGroups: { type: Array, default: () => [] },
    allUsers:   { type: Array, default: () => [] },
})

const emit = defineEmits([
    'group-created',
    'group-updated',
    'group-deleted',
    'group-reordered',
    'user-groups-synced',
    'plannable-toggled',
])

// ── State ──────────────────────────────────────────────────────
const newGroup        = ref(null)
const newNameInput    = ref(null)
const editingGroupId  = ref(null)
const editingName     = ref('')
const editNameInputEl = ref(null)
const dropTargetGroupId = ref(undefined)

// ── Computed ───────────────────────────────────────────────────
const sortedAllUsers = computed(() =>
    [...props.allUsers].sort((a, b) => a.name.localeCompare(b.name))
)

const maxVisibleUsers = 4

const {
    searchQuery: userSearchQuery,
    isExpanded: isUsersExpanded,
    filteredItems: filteredUsers,
    visibleItems: visibleUsers,
} = useExpandableFilter(sortedAllUsers, (user, query) =>
    user.name.toLowerCase().includes(query),
    maxVisibleUsers)

function usersInGroup(groupId) {
    return props.allUsers.filter(u => u.plan_group_ids.includes(groupId))
}

// ── New group ──────────────────────────────────────────────────
function startNewGroup() {
    newGroup.value = { name: '', color: '#2563ff' }
    nextTick(() => newNameInput.value?.focus())
}

function confirmNewGroup() {
    if (!newGroup.value?.name?.trim()) { newGroup.value = null; return }
    emit('group-created', { name: newGroup.value.name.trim(), color: newGroup.value.color })
    newGroup.value = null
}

// ── Edit group name ────────────────────────────────────────────
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

// ── Delete group ───────────────────────────────────────────────
function deleteGroup(group) {
    const count = usersInGroup(group.id).length
    if (count > 0 && !window.confirm(`Groep "${group.name}" heeft ${count} monteur(s). Weet je zeker dat je wilt verwijderen?`)) return
    emit('group-deleted', group.id)
}

// ── User group toggle ──────────────────────────────────────────
function toggleUserGroup(user, groupId) {
    const current = user.plan_group_ids
    const next = current.includes(groupId)
        ? current.filter(id => id !== groupId)
        : [...current, groupId]
    emit('user-groups-synced', user.id, next)
}

// ── Drag reorder (groups only) ─────────────────────────────────
function onGroupDragStart(e, group) {
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('application/x-plan-group', String(group.id))
}

function onGroupDrop(e, targetId) {
    dropTargetGroupId.value = undefined
    if (!e.dataTransfer.types.includes('application/x-plan-group')) return
    const draggedId = parseInt(e.dataTransfer.getData('application/x-plan-group'), 10)
    if (!draggedId || draggedId === targetId) return
    const ids = props.planGroups.map(g => g.id)
    const fromIdx = ids.indexOf(draggedId)
    const toIdx = ids.indexOf(targetId)
    if (fromIdx === -1 || toIdx === -1) return
    const newIds = [...ids]
    newIds.splice(fromIdx, 1)
    newIds.splice(toIdx, 0, draggedId)
    emit('group-reordered', newIds)
}
</script>
