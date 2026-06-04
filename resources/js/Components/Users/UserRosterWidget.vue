<script setup>
import { ref, computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { hasPermission } from '@/Utilities/Utilities.js'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'

const props = defineProps({
    user: { type: Object, required: true },
    unavailabilities: { type: Array, default: () => [] },
})

const page = usePage()

const canManage = computed(() =>
    hasPermission('roster.manage_all') ||
    (hasPermission('roster.manage_own') && props.user.id === page.props.auth.user.id)
)

const DAY_OPTIONS = [
    { value: 0, title: 'Maandag' },
    { value: 1, title: 'Dinsdag' },
    { value: 2, title: 'Woensdag' },
    { value: 3, title: 'Donderdag' },
    { value: 4, title: 'Vrijdag' },
    { value: 5, title: 'Zaterdag' },
    { value: 6, title: 'Zondag' },
]

const REPEAT_OPTIONS = [
    { value: 'weekly',   title: 'Wekelijks' },
    { value: 'biweekly', title: 'Om de week' },
]

const showRecurringForm = ref(false)
const showHolidayForm   = ref(false)

const recurringForm = useForm({
    type:           'recurring',
    day_of_week:    null,
    start_time:     '',
    end_time:       '',
    repeat:         'weekly',
    reference_date: '',
    label:          '',
})

const holidayForm = useForm({
    type:     'holiday',
    date:     '',
    end_date: '',
    label:    '',
})

const recurringEntries = computed(() => props.unavailabilities.filter(u => u.type === 'recurring'))
const holidayEntries   = computed(() => props.unavailabilities.filter(u => u.type === 'holiday'))

function dayLabel(dow) {
    return DAY_OPTIONS.find(d => d.value === dow)?.title ?? String(dow)
}

function submitRecurring() {
    recurringForm.post(`/users/${props.user.id}/unavailabilities`, {
        preserveScroll: true,
        only: ['unavailabilities'],
        onSuccess: () => {
            showRecurringForm.value = false
            recurringForm.reset()
        },
    })
}

function submitHoliday() {
    holidayForm.post(`/users/${props.user.id}/unavailabilities`, {
        preserveScroll: true,
        only: ['unavailabilities'],
        onSuccess: () => {
            showHolidayForm.value = false
            holidayForm.reset()
        },
    })
}

function destroy(id) {
    router.delete(
        `/users/${props.user.id}/unavailabilities/${id}`,
        { preserveScroll: true, only: ['unavailabilities'] }
    )
}
</script>

<template>
    <div v-if="canManage" class="space-y-6">

        <!-- Recurring off-periods -->
        <div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Vaste vrije momenten</h3>

            <div v-if="recurringEntries.length"
                 class="divide-y divide-gray-100 dark:divide-slate-700 border border-gray-200 dark:border-slate-700 rounded mb-3">
                <div v-for="entry in recurringEntries" :key="entry.id"
                     class="flex items-center justify-between px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    <span>
                        {{ dayLabel(entry.day_of_week) }},
                        {{ entry.start_time?.slice(0, 5) }} – {{ entry.end_time?.slice(0, 5) }}
                        <span class="text-gray-400 ml-1">
                            ({{ entry.repeat === 'weekly' ? 'wekelijks' : 'om de week' }})
                        </span>
                        <span v-if="entry.label" class="text-gray-400 ml-1">— {{ entry.label }}</span>
                    </span>
                    <button type="button" @click="destroy(entry.id)"
                            class="ml-3 text-red-400 hover:text-red-600 text-base leading-none">×</button>
                </div>
            </div>
            <p v-else class="text-sm text-gray-400 mb-3">Geen vaste vrije momenten.</p>

            <button v-if="!showRecurringForm" type="button"
                    @click="showRecurringForm = true"
                    class="text-sm text-blue-600 hover:underline">+ Toevoegen</button>

            <form v-else @submit.prevent="submitRecurring"
                  class="space-y-3 border border-gray-200 dark:border-slate-600 rounded p-3 bg-gray-50 dark:bg-slate-800/50">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Dag</label>
                        <SelectMenuComponent v-model="recurringForm.day_of_week"
                                             :options="DAY_OPTIONS" label="Kies dag" />
                        <p v-if="recurringForm.errors.day_of_week" class="text-xs text-red-500 mt-1">
                            {{ recurringForm.errors.day_of_week }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Herhaling</label>
                        <SelectMenuComponent v-model="recurringForm.repeat"
                                             :options="REPEAT_OPTIONS" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Van</label>
                        <input v-model="recurringForm.start_time" type="time"
                               class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                        <p v-if="recurringForm.errors.start_time" class="text-xs text-red-500 mt-1">
                            {{ recurringForm.errors.start_time }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Tot</label>
                        <input v-model="recurringForm.end_time" type="time"
                               class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                        <p v-if="recurringForm.errors.end_time" class="text-xs text-red-500 mt-1">
                            {{ recurringForm.errors.end_time }}
                        </p>
                    </div>
                </div>

                <div v-if="recurringForm.repeat === 'biweekly'">
                    <label class="block text-xs text-gray-500 mb-1">Eerste keer (ankerdatum)</label>
                    <input v-model="recurringForm.reference_date" type="date"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                    <p v-if="recurringForm.errors.reference_date" class="text-xs text-red-500 mt-1">
                        {{ recurringForm.errors.reference_date }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Label (optioneel)</label>
                    <input v-model="recurringForm.label" type="text" placeholder="bijv. Parttime dag"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                </div>

                <div class="flex gap-2">
                    <button type="submit" :disabled="recurringForm.processing"
                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                        Opslaan
                    </button>
                    <button type="button"
                            @click="showRecurringForm = false; recurringForm.reset()"
                            class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                        Annuleren
                    </button>
                </div>
            </form>
        </div>

        <!-- Holidays -->
        <div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Vrije dagen</h3>

            <div v-if="holidayEntries.length"
                 class="divide-y divide-gray-100 dark:divide-slate-700 border border-gray-200 dark:border-slate-700 rounded mb-3">
                <div v-for="entry in holidayEntries" :key="entry.id"
                     class="flex items-center justify-between px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    <span>
                        {{ entry.date }}
                        <span v-if="entry.end_date && entry.end_date !== entry.date"> – {{ entry.end_date }}</span>
                        <span v-if="entry.label" class="text-gray-400 ml-1">— {{ entry.label }}</span>
                    </span>
                    <button type="button" @click="destroy(entry.id)"
                            class="ml-3 text-red-400 hover:text-red-600 text-base leading-none">×</button>
                </div>
            </div>
            <p v-else class="text-sm text-gray-400 mb-3">Geen vrije dagen.</p>

            <button v-if="!showHolidayForm" type="button"
                    @click="showHolidayForm = true"
                    class="text-sm text-blue-600 hover:underline">+ Toevoegen</button>

            <form v-else @submit.prevent="submitHoliday"
                  class="space-y-3 border border-gray-200 dark:border-slate-600 rounded p-3 bg-gray-50 dark:bg-slate-800/50">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Van</label>
                        <input v-model="holidayForm.date" type="date"
                               class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                        <p v-if="holidayForm.errors.date" class="text-xs text-red-500 mt-1">
                            {{ holidayForm.errors.date }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Tot (optioneel)</label>
                        <input v-model="holidayForm.end_date" type="date"
                               :min="holidayForm.date"
                               class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                        <p v-if="holidayForm.errors.end_date" class="text-xs text-red-500 mt-1">
                            {{ holidayForm.errors.end_date }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Label (optioneel)</label>
                    <input v-model="holidayForm.label" type="text" placeholder="bijv. Vakantie"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                </div>

                <div class="flex gap-2">
                    <button type="submit" :disabled="holidayForm.processing"
                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                        Opslaan
                    </button>
                    <button type="button"
                            @click="showHolidayForm = false; holidayForm.reset()"
                            class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                        Annuleren
                    </button>
                </div>
            </form>
        </div>

    </div>
</template>
