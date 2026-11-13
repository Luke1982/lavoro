<template>
    <div>
        <ComboBox :key="pickerKey" :options="options" :model-value="null" label="Extra checks (optioneel)"
            placeholder="Zoek een check of checklist..." create-label="Eigen check" @update:model-value="pick"
            @create="addTitle" />
        <div v-if="pills.length" class="mt-2 flex flex-wrap gap-1.5">
            <button v-for="pill in pills" :key="pill.key" type="button" @click="remove(pill)"
                v-tooltip="'Klik om weg te halen'"
                class="inline-flex items-center gap-1 rounded-full border border-lavoro-blue/30 bg-lavoro-blue/10 px-2.5 py-1 text-xs font-medium text-lavoro-blue hover:bg-lavoro-blue/20 dark:text-sky-300">
                <ListChecksIcon v-if="pill.kind === 'checklist'" class="w-3.5 h-3.5" />
                {{ pill.name }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { ListChecks as ListChecksIcon } from '@lucide/vue'
import ComboBox from '@/Components/UI/ComboBox.vue'

/**
 * Optional checks added to one werkbon task on top of what its template brings:
 * checks or whole checklists from the library, or a title typed for this task
 * only. Each pick shows as a pill; clicking a pill takes it off again.
 */
const model = defineModel({
    type: Object,
    default: () => ({ check_ids: [], checklist_ids: [], titles: [] }),
})

const props = defineProps({
    checks: { type: Array, default: () => [] },
    checklists: { type: Array, default: () => [] },
})

/** Remounts the box after every pick: it clears the search and the picked row, which the box has no other way to do. */
const pickerKey = ref(0)

const options = computed(() => [
    ...props.checks
        .filter(check => !model.value.check_ids.includes(check.id))
        .map(check => ({ id: 'c' + check.id, name: check.title })),
    ...props.checklists
        .filter(list => !model.value.checklist_ids.includes(list.id))
        .map(list => ({ id: 'l' + list.id, name: 'Checklist: ' + list.title })),
])

const pills = computed(() => [
    ...model.value.checklist_ids.map(id => ({
        key: 'l' + id, kind: 'checklist', id, name: props.checklists.find(list => list.id === id)?.title ?? 'Checklist',
    })),
    ...model.value.check_ids.map(id => ({
        key: 'c' + id, kind: 'check', id, name: props.checks.find(check => check.id === id)?.title ?? 'Check',
    })),
    ...model.value.titles.map(title => ({ key: 't' + title, kind: 'title', id: title, name: title })),
])

function update(changes) {
    model.value = { ...model.value, ...changes }
    pickerKey.value++
}

function pick(value) {
    if (!value) return

    const id = Number(String(value).slice(1))

    if (String(value).startsWith('l')) {
        update({ checklist_ids: [...model.value.checklist_ids, id] })
    } else {
        update({ check_ids: [...model.value.check_ids, id] })
    }
}

function addTitle(title) {
    const clean = (title ?? '').trim()

    if (clean && !model.value.titles.includes(clean)) {
        update({ titles: [...model.value.titles, clean] })
    }
}

function remove(pill) {
    if (pill.kind === 'checklist') update({ checklist_ids: model.value.checklist_ids.filter(id => id !== pill.id) })
    if (pill.kind === 'check') update({ check_ids: model.value.check_ids.filter(id => id !== pill.id) })
    if (pill.kind === 'title') update({ titles: model.value.titles.filter(title => title !== pill.id) })
}
</script>
