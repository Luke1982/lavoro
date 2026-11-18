<template>
    <DrawerComponent v-model="open" title="Planning exporteren"
        subtitle="Selecteer monteurs en een periode. Je ontvangt een Excel met een werkblad per monteur.">
        <div class="p-4 sm:p-6 space-y-6">
            <div class="grid grid-cols-2 gap-3">
                <TextInput v-model="startDate" type="date" label="Van" />
                <TextInput v-model="endDate" type="date" label="Tot en met" />
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Monteurs</span>
                    <button type="button"
                        class="text-xs font-medium text-lavoro-blue dark:text-blue-400 hover:underline"
                        @click="toggleAll">
                        {{ allSelected ? 'Selectie wissen' : 'Alles selecteren' }}
                    </button>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-slate-700 rounded-md border border-gray-200 dark:border-slate-700 overflow-hidden">
                    <li v-for="user in plannableUsers" :key="user.id">
                        <button type="button"
                            class="w-full flex items-center gap-3 px-3 py-2 text-left text-sm transition-colors"
                            :class="isSelected(user.id)
                                ? 'bg-lavoro-blue/10 text-gray-900 dark:text-white'
                                : 'hover:bg-gray-50 dark:hover:bg-slate-700/50 text-gray-700 dark:text-slate-200'"
                            @click="toggle(user.id)">
                            <span class="size-7 rounded-full bg-gray-200 dark:bg-slate-600 flex items-center justify-center text-[10px] font-semibold overflow-hidden shrink-0">
                                <img v-if="user.avatar" :src="user.avatar" class="w-full h-full object-cover" :alt="user.name" />
                                <span v-else>{{ initials(user.name) }}</span>
                            </span>
                            <span class="flex-1 truncate">{{ user.name }}</span>
                            <Check v-if="isSelected(user.id)" class="size-4 shrink-0 text-lavoro-blue dark:text-blue-400" />
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <template #footer>
            <ExcelExportButton class="w-full" url="/planner/export" :disabled="!canExport"
                :params="{ start: startDate, end: endDate, user_ids: selectedIds }"
                @downloaded="open = false" />
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref, computed } from 'vue'
import dayjs from '@/Utilities/dayjs'
import { initials } from '@/Utilities/Utilities'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { Check } from '@lucide/vue'
import ExcelExportButton from '@/Components/UI/ExcelExportButton.vue'

const open = defineModel({ type: Boolean, default: false })

const props = defineProps({
    plannableUsers: { type: Array, default: () => [] },
})

const startDate = ref(dayjs().startOf('isoWeek').format('YYYY-MM-DD'))
const endDate = ref(dayjs().endOf('isoWeek').format('YYYY-MM-DD'))
const selectedIds = ref([])

function isSelected(id) {
    return selectedIds.value.includes(id)
}

function toggle(id) {
    selectedIds.value = isSelected(id)
        ? selectedIds.value.filter(existing => existing !== id)
        : [...selectedIds.value, id]
}

const allSelected = computed(() =>
    props.plannableUsers.length > 0 && selectedIds.value.length === props.plannableUsers.length
)

function toggleAll() {
    selectedIds.value = allSelected.value ? [] : props.plannableUsers.map(u => u.id)
}

const canExport = computed(() =>
    selectedIds.value.length > 0
    && !!startDate.value
    && !!endDate.value
    && startDate.value <= endDate.value
)
</script>
