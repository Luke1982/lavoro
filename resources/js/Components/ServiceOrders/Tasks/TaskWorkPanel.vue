<template>
    <div class="space-y-5">
        <p v-if="serialGroups.length" class="text-xs text-gray-500 dark:text-slate-400">
            Elke machine wordt los opgeslagen. Je kunt tussendoor stoppen en later verder gaan.
        </p>

        <template v-if="!perUnit">
            <div v-for="bundle in bundles" :key="bundle.key" :class="bundle.label
                ? 'rounded-lavoro-sm border border-gray-200 dark:border-slate-700 p-3 space-y-4'
                : ''">
                <p v-if="bundle.label"
                    class="text-sm font-semibold text-gray-800 dark:text-slate-200 flex items-center gap-2">
                    <PackageIcon class="w-4 h-4 text-gray-400" />
                    {{ bundle.label }}
                    <span class="text-xs font-normal text-gray-400">bundelmachine, geen serienummer</span>
                </p>
                <SerialGroups :groups="bundle.groups" :submitting="submitting" :readonly="!canToggle"
                    @save="save" @type="type" />
            </div>
            <TaskCheckList v-if="units.length" :checks="units[0].checks" :pending="pending" :reverts="reverts"
                :disabled="!canTick" @tick="tick" />
        </template>

        <template v-else>
            <div v-for="block in unitBlocks" :key="block.unit.key"
                class="rounded-lavoro-sm border border-gray-200 dark:border-slate-700 p-3 space-y-3">
                <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 flex items-center gap-2">
                    <PackageIcon class="w-4 h-4 text-gray-400" />
                    {{ block.unit.label }}
                </p>
                <SerialGroups v-if="block.groups.length" :groups="block.groups" :submitting="submitting"
                    :readonly="!canToggle" :number-from="isBundle ? 1 : block.unit.position" @save="save"
                    @type="type" />
                <TaskCheckList :checks="block.unit.checks" :pending="pending" :reverts="reverts" :disabled="!canTick"
                    @tick="tick" />
            </div>
        </template>

        <p v-if="serialError" class="text-xs text-red-600">{{ serialError }}</p>

        <div v-if="canReopen" class="flex justify-end">
            <button type="button" @click="$emit('reopen')"
                class="text-sm text-gray-500 underline hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                Taak heropenen
            </button>
        </div>
        <div v-else-if="!instance.is_complete && !instance.is_cancelled" class="flex flex-col items-end gap-1.5">
            <button type="button" :disabled="!canSubmit" @click="submit"
                class="px-4 py-2 rounded-lavoro-sm text-sm font-semibold bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                {{ primaryLabel }}
            </button>
            <p v-for="reason in blockers" :key="reason" class="text-xs text-amber-600 dark:text-amber-400">
                {{ reason }}
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed, toRef } from 'vue'
import { Package as PackageIcon } from '@lucide/vue'
import SerialGroups from '@/Components/ServiceOrders/Tasks/SerialGroups.vue'
import TaskCheckList from '@/Components/ServiceOrders/Tasks/TaskCheckList.vue'
import { useTaskChecks } from '@/Composables/useTaskChecks.js'
import { useTaskSerials } from '@/Composables/useTaskSerials.js'

/**
 * Everything a mechanic does to finish one task: register its machines, tick its
 * checks, and complete it. Per machine, each machine gets a block with its serial
 * and its own checks; otherwise the machines come first and the checks once below.
 *
 * Shown inside the task's drawer in the standaard view and folded open in place in
 * the monteur view, so both views work through exactly the same steps. Completing
 * itself is left to the parent, which already knows how to tick a task off and what
 * to do when that is refused.
 */
const props = defineProps({
    instance: { type: Object, required: true },
    canToggle: { type: Boolean, default: false },
})

const emit = defineEmits(['complete', 'reopen'])

const instance = toRef(props, 'instance')
const { units, perUnit, openRequired, pending, reverts, tick } = useTaskChecks(instance)
const {
    groups: serialGroups, bundles, hasDirty, ready, missing, submitting, error: serialError, forUnit, type, save,
} = useTaskSerials(instance, units)

const isBundle = computed(() => !!props.instance.product?.bundle)
/** The server never reopens a task whose machines are registered, so the link is not offered there. */
const canReopen = computed(() => props.instance.is_complete && props.canToggle
    && !(props.instance.product_id && props.instance.assets?.length))
const canTick = computed(() => props.canToggle && !props.instance.is_complete && !props.instance.is_cancelled)
const unitBlocks = computed(() => units.value.map(unit => ({ unit, groups: forUnit(unit) })))

const canComplete = computed(() => !props.instance.is_complete && !props.instance.is_cancelled
    && ready.value && openRequired.value === 0)

const canSubmit = computed(() => props.canToggle && !submitting.value && pending.size === 0
    && (hasDirty.value || canComplete.value))

const blockers = computed(() => {
    if (canComplete.value) return []

    const n = openRequired.value
    const m = missing.value

    return [
        n > 0 ? `Nog ${n} verplichte ${n === 1 ? 'check' : 'checks'} open` : null,
        m > 0 ? `Nog ${m} ${m === 1 ? 'machine' : 'machines'} te registreren` : null,
    ].filter(Boolean)
})

const primaryLabel = computed(() => {
    if (submitting.value) return 'Opslaan...'
    if (hasDirty.value) return canComplete.value ? 'Opslaan en voltooien' : 'Opslaan'

    return 'Taak voltooien'
})

async function submit() {
    const completing = canComplete.value

    if (await save() && completing) emit('complete')
}
</script>
