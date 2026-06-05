<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'

const page = usePage()
const status = ref(null)
const polling = ref(null)

const integration = computed(() => page.props.auth.user?.google_integration ?? null)
const connected = computed(() => !!integration.value)
const disabled = computed(() => !!integration.value?.disabled_at)

async function fetchStatus() {
    try {
        const { data } = await axios.get('/api/google/integration/status')
        status.value = data
        if (data.connected && data.backfill_total && data.backfill_done < data.backfill_total) {
            return
        }
        if (polling.value) {
            clearInterval(polling.value)
            polling.value = null
        }
    } catch (e) {
        // ignore
    }
}

function startPolling() {
    fetchStatus()
    if (polling.value) return
    polling.value = setInterval(fetchStatus, 4000)
}

function connect() {
    window.location.href = '/google/oauth/start'
}

function disconnect() {
    if (!confirm('Weet je zeker dat je de Google Agenda-koppeling wilt opheffen?')) return
    router.delete('/google/integration', { preserveScroll: true })
}

onMounted(() => {
    if (connected.value) startPolling()
})

onBeforeUnmount(() => {
    if (polling.value) {
        clearInterval(polling.value)
        polling.value = null
    }
})
</script>

<template>
    <section class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm">
        <div class="px-6 py-5 border-b border-gray-200 dark:border-slate-800">
            <h2 class="text-sm font-medium text-gray-900 dark:text-slate-100">Google Agenda</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                Koppel je Google-account om je afspraken te synchroniseren met Google Agenda.
            </p>
        </div>
        <div class="p-6 space-y-4">
            <div v-if="!connected">
                <p class="text-sm text-gray-700 dark:text-slate-300">
                    Nog geen Google Agenda gekoppeld.
                </p>
                <button type="button"
                    class="mt-3 inline-flex items-center gap-2 rounded-md bg-lavoro-blue px-4 py-2 text-sm font-medium text-white shadow hover:opacity-90"
                    @click="connect">
                    Google Agenda koppelen
                </button>
            </div>

            <div v-else class="space-y-3">
                <div v-if="disabled"
                    class="rounded-md border border-amber-300 bg-amber-50 dark:bg-amber-950 dark:border-amber-800 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                    Synchronisatie gepauzeerd — koppel opnieuw om verder te gaan.
                    <button type="button" class="ml-2 underline" @click="connect">Opnieuw koppelen</button>
                </div>

                <p class="text-sm text-gray-700 dark:text-slate-300">
                    Gekoppeld als <strong class="font-medium text-gray-900 dark:text-slate-100">{{ integration.email }}</strong>
                </p>

                <div v-if="status && status.backfill_total && status.backfill_done < status.backfill_total"
                    class="text-xs text-gray-500 dark:text-slate-400">
                    Backfill bezig: {{ status.backfill_done }} / {{ status.backfill_total }} afspraken
                </div>

                <button type="button"
                    class="inline-flex items-center rounded-md border border-gray-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-slate-200 shadow-sm hover:bg-gray-50 dark:hover:bg-slate-700"
                    @click="disconnect">
                    Koppeling opheffen
                </button>
            </div>
        </div>
    </section>
</template>
