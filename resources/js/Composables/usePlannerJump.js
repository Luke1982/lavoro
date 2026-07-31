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
 */
export function takeJumpTarget() {
    const params = new URLSearchParams(window.location.search)
    const id = params.get('highlightevent')

    if (!id) return null

    const target = {
        id: Number(id),
        start: params.get('gotodate') || new Date().toISOString(),
        executing_users: (params.get('executing_user_ids') || '')
            .split(',')
            .filter(Boolean)
            .map(value => ({ id: Number(value) })),
    }

    window.history.replaceState(null, '', window.location.pathname)

    return target
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
