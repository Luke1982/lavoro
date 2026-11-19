import { router } from '@inertiajs/vue3'

const REFRESH_HEADER = 'X-Restored-Page-Refresh'

const holdsSavedPage = (historyState) => Boolean(historyState?.page)

const isRefreshRequest = (config) => Boolean(config?.headers[REFRESH_HEADER])

/**
 * Terug en vooruit vragen de server niets: Inertia zet de kopie terug die het
 * bij het weggaan bewaarde, en een werkbon die intussen van fase wisselde stond
 * nog in een lijst die op zijn oude fase filtert. Daarom haalt een teruggezette
 * pagina meteen de actuele gegevens op; filters, scrollpositie en wat er getypt
 * staat blijven.
 *
 * Teruggezet wordt bij terug of vooruit binnen de app, bij de bevroren pagina
 * die de browser na een andere site terugzet, en bij een volledige paginalading
 * waarin Inertia toch de bewaarde kopie neemt.
 *
 * Inertia breekt een lopende verversing niet af; kwam die te laat binnen, dan
 * zette hij de filters terug van een scherm dat je al verlaten had. Elke
 * volgende stap breekt hem daarom af, behalve een stap die maar een deel van de
 * pagina ophaalt.
 *
 * Mislukt de verversing, ook zonder verbinding, dan blijft de kopie staan zoals
 * voorheen, zonder foutmelding: niet elke pagina is opnieuw op te vragen. Een
 * importvoorbeeld bestaat alleen als antwoord op het uploaden, en een
 * verwijderde werkbon is weg.
 */
export function refreshRestoredPages() {
    let restoring = window.performance.getEntriesByType('navigation')[0]?.type === 'back_forward'
        && holdsSavedPage(window.history.state)
    let cancelRefresh = () => {}

    const refresh = () => router.reload({
        headers: { [REFRESH_HEADER]: '1' },
        showProgress: true,
        onCancelToken: (token) => {
            cancelRefresh = token.cancel
        },
    })

    window.addEventListener('popstate', (event) => {
        if (!holdsSavedPage(event.state)) return

        cancelRefresh()
        restoring = true
    })

    router.on('navigate', () => {
        if (!restoring) return

        restoring = false
        refresh()
    })

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) refresh()
    })

    router.on('before', (event) => {
        const { only, except } = event.detail.visit

        if (only.length || except.length) return

        cancelRefresh()
        restoring = false
    })

    router.on('invalid', (event) => {
        if (isRefreshRequest(event.detail.response.config)) event.preventDefault()
    })

    router.on('exception', (event) => {
        if (isRefreshRequest(event.detail.exception.config)) event.preventDefault()
    })
}
