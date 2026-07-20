import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useDocumentVisibility } from '@vueuse/core'
import axios from 'axios'

/**
 * Polls the server for the last time a project's financial notes were saved,
 * so a second editor working on the same sheet can be warned before their save
 * overwrites someone else's. Polling pauses while the tab is hidden and while a
 * save of our own is in flight, since that would otherwise report our own write
 * as a foreign change.
 */
export function useFinancialNotesFreshness(projectId, options = {}) {
    const {
        initialSavedAt = null,
        intervalMs = 20000,
        isPaused = ref(false),
        enabled = true,
    } = options

    const baseline = ref(initialSavedAt ?? null)
    const remoteSavedAt = ref(initialSavedAt ?? null)
    const remoteSavedBy = ref(null)

    const visibility = useDocumentVisibility()

    let timer = null

    const isStale = computed(() => {
        if (!remoteSavedAt.value) return false
        return remoteSavedAt.value !== baseline.value
    })

    const staleByName = computed(() => remoteSavedBy.value?.name ?? null)

    function markSaved(savedAt) {
        baseline.value = savedAt ?? null
        remoteSavedAt.value = savedAt ?? null
    }

    async function check() {
        if (!enabled || isPaused.value || visibility.value !== 'visible') return

        try {
            const response = await axios.get(`/api/projects/${projectId}/financial-notes/state`)
            remoteSavedAt.value = response.data.saved_at ?? null
            remoteSavedBy.value = response.data.saved_by ?? null
        } catch {
            // a failed poll should never disrupt editing; the next tick retries
        }
    }

    function start() {
        if (!enabled) return

        stop()
        timer = setInterval(check, intervalMs)
    }

    function stop() {
        if (timer) {
            clearInterval(timer)
            timer = null
        }
    }

    watch(visibility, (value) => {
        if (value === 'visible') {
            check()
        }
    })

    onMounted(start)
    onBeforeUnmount(stop)

    return { isStale, staleByName, remoteSavedAt, markSaved, check }
}
