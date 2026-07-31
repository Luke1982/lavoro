<template>
    <IndexHeaderComponent title="Meldingen" :subtitle="subtitle" :show-search="false" />

    <BoxComponent class="mt-4">
        <!-- Wie de voorkeuren van anderen mag zetten, kiest hier voor wie. -->
        <div v-if="manages_others" class="mb-4 max-w-sm">
            <label class="mb-1 block text-sm font-bold text-gray-900 dark:text-slate-200">Voor wie</label>
            <ComboBox v-model="chosen_user" :options="colleagues" placeholder="Kies een collega" />
        </div>

        <ul class="divide-y divide-gray-200 dark:divide-slate-700">
            <li v-for="type in rows" :key="type.value" class="flex items-center gap-x-4 py-4">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full"
                    :class="colorClass(type.color)">
                    <component :is="navIcon(type.icon)" v-if="navIcon(type.icon)" class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ type.label }}</p>
                    <p class="text-[13px] text-gray-500 dark:text-slate-400">{{ type.description }}</p>
                    <!--
                        Een soort dat deze persoon niet mag lezen staat er wel, maar
                        uit: het is geen ontbrekend soort, het is een ontbrekend recht.
                    -->
                    <p v-if="!type.allowed" class="mt-1 text-[13px] text-amber-600 dark:text-amber-400">
                        {{ subject.name }} mag dit niet inzien en kan er dus geen melding van krijgen.
                    </p>
                </div>

                <!--
                    Uit is hier neutraal en niet rood: niet aanstaan is geen fout,
                    en de schakelaar kent daar een eigen stand voor.
                -->
                <SwitchComponent :model-value="type.subscription_id ? true : null"
                    :disabled="!type.allowed || busy === type.value" @update="value => toggle(type, value)" />
            </li>
        </ul>

        <p v-if="error" class="mt-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </BoxComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { navIcon } from '@/Navigation/icons.js'

/**
 * Waar je aanzet waarover je bericht wilt. De schakelaars sturen naar dezelfde
 * endpoints als altijd, dus wat er niet mag komt als melding van de server terug
 * en wordt hier alleen getoond.
 */
const props = defineProps({
    types: { type: Array, required: true },
    subject: { type: Object, required: true },
    colleagues: { type: Array, default: () => [] },
    manages_others: { type: Boolean, default: false },
})

const COLORS = {
    blue: 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
    amber: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    green: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    purple: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
    red: 'bg-red-500/15 text-red-600 dark:text-red-400',
}

const colorClass = (color) => COLORS[color] ?? COLORS.blue

const rows = computed(() => props.types)

const subtitle = computed(() => (props.manages_others
    ? 'Instellen waarover ' + props.subject.name + ' bericht krijgt'
    : 'Instellen waarover je bericht krijgt'))

const chosen_user = ref(props.subject.id)
const busy = ref(null)
const error = ref('')

/** Iemand anders kiezen laadt diens voorkeuren; de URL houdt bij wie. */
watch(chosen_user, (id) => {
    if (!id || id === props.subject.id) return
    router.get('/notificationsubscriptions', { user_id: id }, { preserveScroll: true })
})

const toggle = (type, wanted) => {
    if (busy.value) return

    busy.value = type.value
    error.value = ''

    const done = {
        preserveScroll: true,
        onFinish: () => { busy.value = null },
        onError: (errors) => { error.value = Object.values(errors)[0] ?? 'Dit lukte niet.' },
    }

    if (wanted && !type.subscription_id) {
        return useForm({ user_id: props.subject.id, type: type.value })
            .post('/notificationsubscriptions', done)
    }

    if (!wanted && type.subscription_id) {
        return useForm({}).delete('/notificationsubscriptions/' + type.subscription_id, done)
    }

    busy.value = null
}
</script>
