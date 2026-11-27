import { watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useEventListener } from '@vueuse/core'
import { browserSession } from '@/Utilities/browserStorage'

/**
 * Onthoudt in dit tabblad hoe ver een vak horizontaal en verticaal gescrold is,
 * en zet het terug zodra het vak er weer is en `ready` zegt dat zijn inhoud
 * staat. Een vak dat opnieuw verschijnt, na een andere weergave, krijgt zijn
 * plek opnieuw.
 *
 * Tot de plek terug is wordt niets bewaard: het lege vak van vlak na het laden
 * zou de onthouden plek anders met nul overschrijven.
 *
 * Bepaalt iets anders waar het vak moet staan, zoals een sprong naar één
 * afspraak, dan roept dat `skipRestore()` aan en blijft het vak waar het is.
 *
 * @param {string} name
 * @param {import('vue').Ref<HTMLElement|null>} elRef
 * @param {import('vue').WatchSource<boolean>} ready
 */
export function useScrollMemory(name, elRef, ready = () => true) {
    const key = 'lavoro_scroll_' + name + '_' + usePage().props.auth.user?.id
    let restoredEl = null
    let skip = false

    function stored() {
        try {
            const position = JSON.parse(browserSession.get(key))

            return Number.isFinite(position?.left) && Number.isFinite(position?.top) ? position : null
        } catch {
            return null
        }
    }

    useEventListener(elRef, 'scroll', () => {
        const el = elRef.value
        if (el !== restoredEl) return

        browserSession.set(key, JSON.stringify({ left: el.scrollLeft, top: el.scrollTop }))
    }, { passive: true })

    watch([elRef, ready], ([el, isReady]) => {
        if (!el || !isReady || el === restoredEl) return

        const position = skip ? null : stored()
        if (position) el.scrollTo({ ...position, behavior: 'instant' })

        restoredEl = el
        skip = false
    }, { immediate: true, flush: 'post' })

    return {
        skipRestore: () => { skip = true },
    }
}
