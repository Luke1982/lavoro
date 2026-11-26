import { onMounted, onUnmounted } from 'vue'
import { SAME_PAGE_EVENT } from '@/Composables/useNavigator.js'

/**
 * De afspraak waar de planner naartoe moet, zoals een melding of een zoekopdracht
 * hem in het adres achterlaat: welke afspraak, welke dag, en wie hem uitvoert.
 *
 * Eén keer lezen en meteen uit het adres halen. Na de sprong is dit gewoon de
 * planner, en een verversing of een stap terug hoort niet opnieuw te springen naar
 * een afspraak waar je al klaar mee bent.
 *
 * De twee planners doen er ieder iets anders mee — de een zet een week en klapt
 * een monteur open, de ander schakelt een filter om — maar ze lezen hetzelfde,
 * en dat staat daarom hier en niet twee keer.
 *
 * Beide planners staan altijd op de pagina, de een verborgen per schermbreedte.
 * Alleen de zichtbare mag de sprong pakken: de verborgen telefoonplanner komt
 * als eerste aan de beurt en zou het adres leeghalen voor de echte.
 */
export function takeJumpTarget(root) {
    if (!isShown(root)) return null

    const target = readJumpTarget(window.location.search)

    if (target) window.history.replaceState(null, '', window.location.pathname)

    return target
}

/**
 * Een sprong terwijl de planner al open staat: het zoekvenster bovenin geeft het
 * adres van de afspraak door in plaats van de pagina opnieuw te laden. Alleen de
 * zichtbare planner neemt hem aan; die zegt dat met preventDefault(), zodat het
 * zoekvenster weet dat het niet zelf hoeft te navigeren.
 */
export function onJumpRequest(rootRef, jump) {
    function handle(event) {
        if (!isShown(rootRef.value)) return

        const target = readJumpTarget(new URL(event.detail.href, window.location.origin).search)

        if (!target) return

        event.preventDefault()
        jump(target)
    }

    onMounted(() => window.addEventListener(SAME_PAGE_EVENT, handle))
    onUnmounted(() => window.removeEventListener(SAME_PAGE_EVENT, handle))
}

function isShown(root) {
    return !root || root.getClientRects().length > 0
}

function readJumpTarget(search) {
    const params = new URLSearchParams(search)
    const id = params.get('highlightevent')

    if (!id) return null

    return {
        id: Number(id),
        start: params.get('gotodate') || new Date().toISOString(),
        executing_users: (params.get('executing_user_ids') || '')
            .split(',')
            .filter(Boolean)
            .map(value => ({ id: Number(value) })),
    }
}

/**
 * De werkbon die vanaf een werkbonscherm mee de planner in komt. Zelfde afspraak
 * als hierboven: één keer lezen en meteen uit het adres halen.
 */
export function takeServiceOrderJumpTarget() {
    const params = new URLSearchParams(window.location.search)
    const id = params.get('highlightserviceorder')

    if (!id) return null

    window.history.replaceState(null, '', window.location.pathname)

    return Number(id)
}

/**
 * De rijen worden pas getekend als de afspraken binnen zijn, en bij een sprong
 * vanuit een melding komt het opbouwen van de pagina daar nog bij. Eén keer kijken
 * is te snel geoordeeld; twee seconden geduld is genoeg gebleken.
 */
export async function waitForEventElement(container, id, attempts = 20) {
    for (let i = 0; i < attempts; i++) {
        const el = (container?.value ?? container ?? document).querySelector(`[data-event-id="${id}"]`)
        if (el) return el
        await new Promise(resolve => setTimeout(resolve, 100))
    }

    return null
}
