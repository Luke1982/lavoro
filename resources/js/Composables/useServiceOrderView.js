import { nextTick, ref, toValue, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Standaard of monteur: hoe de werkbonpagina zich toont.
 *
 * Wie de werkbon uitvoert krijgt de monteursweergave, de rest de standaard. Een
 * keuze met de schakelaar geldt daarna voor elke werkbon, op dit toestel en
 * voor deze gebruiker: een planner die ook weleens uitvoert en liever de
 * standaard houdt, hoeft niet op elke werkbon opnieuw om te schakelen.
 *
 * @param {import('vue').MaybeRefOrGetter<boolean>} viewerExecutes
 */
export function useServiceOrderView(viewerExecutes) {
    const key = 'lavoro_werkbon_view_' + (usePage().props.auth?.user?.id ?? 'guest')

    function readChoice() {
        try {
            const stored = localStorage.getItem(key)

            return stored === 'monteur' || stored === 'standaard' ? stored : null
        } catch {
            return null
        }
    }

    const view = ref(readChoice() ?? (toValue(viewerExecutes) ? 'monteur' : 'standaard'))
    let remembering = true

    watch(view, (chosen) => {
        if (!remembering) return
        try {
            localStorage.setItem(key, chosen)
        } catch {
            /* zonder geheugen geldt gewoon weer de standaard bij het volgende bezoek */
        }
    })

    /**
     * Voor een rondleiding: toont `chosen` zonder het als keuze te onthouden, en
     * zegt hoe het terug moet. Null als die weergave er al staat.
     */
    function preview(chosen) {
        if (view.value === chosen) return null
        const before = view.value
        remembering = false
        view.value = chosen

        return async () => {
            view.value = before
            await nextTick()
            remembering = true
        }
    }

    return { view, preview }
}
