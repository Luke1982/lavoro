import { onBeforeUnmount, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useScrollLock } from '@/Composables/useScrollLock.js'

/**
 * Een lijst die minstens zo'n deel van het scherm hoog is, is de pagina zelf:
 * de planner scrolt zijn week in een eigen vak en niet in het venster. Een
 * keuzelijst of een vak in een venster scrolt ook, maar dat is geen lezen.
 */
const PAGE_SHARE = 0.5

/** Zo lang na de laatste beweging, met de duim van het scherm, valt een half weggeschoven balk op zijn plek. */
const SETTLE_DELAY = 140

const SETTLE_DURATION = 220

let activeReveal = null

/**
 * Een pagina die haar vak voor een ander vak omwisselt, staat daarin weer
 * bovenaan zonder dat er gescrold is. Was de balk weg, dan gaapt daar het gat
 * waar hij hoort te staan.
 */
export function revealAppHeader() {
    activeReveal?.()
}

/**
 * De balk bovenin op een telefoon schuift mee met de duim, zoals bij X: tien
 * pixels naar beneden scrollen schuift hem tien pixels weg, omhoog scrollen haalt
 * hem even ver terug. Blijft hij half staan als je loslaat, dan valt hij naar de
 * kant waar hij het dichtst bij is. Dat geldt voor het venster en voor elk vak dat
 * de pagina zelf is, zodat geen pagina het hoeft te regelen.
 *
 * Hij schuift nooit verder weg dan er gescrold is: bovenaan staat hij altijd
 * helemaal, anders gaapt er een gat boven de pagina.
 *
 * Een vak dat zelf scrolt mag niet meeschuiven met de balk, want dan liep de
 * lijst twee keer zo snel als je vinger. Zo'n vak staat vast tegen de bovenrand,
 * begint met --app-header-height lege ruimte en scrolt onder de balk door, zoals
 * de week in de planner. Wat tegen de balk aan plakt leest uit
 * --app-header-offset hoeveel er van de balk te zien is, en volgt hem met de
 * utility follows-app-header.
 *
 * Wordt een vak hoger of lager, bijvoorbeeld als de adresbalk van de browser
 * inklapt, dan verschuift zijn scrollpositie zonder dat iemand scrolt. Die keer
 * telt niet mee.
 *
 * Een geopende foto of het afrondvenster zet de pagina even vast en springt
 * daarbij naar boven en weer terug. Dat is geen scrollen en telt dus niet mee.
 *
 * @param {import('vue').Ref<HTMLElement|null>} header
 */
export function useHeaderReveal(header) {
    const { isLocked } = useScrollLock()
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)')

    const seen = new WeakMap()
    let offset = 0
    let source = null
    let sourceY = 0
    let frame = null
    let settleTimer = null
    let touching = false
    let stopNavigate = null

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max)
    }

    function position(scroller) {
        if (scroller === window) {
            return {
                y: window.scrollY,
                bottom: document.documentElement.scrollHeight - window.innerHeight,
                height: window.innerHeight,
            }
        }

        return {
            y: scroller.scrollTop,
            bottom: scroller.scrollHeight - scroller.clientHeight,
            height: scroller.clientHeight,
        }
    }

    function isPage(scroller) {
        return scroller.clientHeight >= window.innerHeight * PAGE_SHARE && !scroller.closest('[role="dialog"]')
    }

    /** Een balk die er niet staat, zoals op een groot scherm, is nul hoog en schuift dus nergens heen. */
    function headerHeight() {
        return header.value?.offsetHeight ?? 0
    }

    function move(next, animate) {
        const height = headerHeight()
        const root = document.documentElement.style

        offset = clamp(next, 0, height)

        root.setProperty('--app-header-transition', (animate && !reducedMotion.matches ? SETTLE_DURATION : 0) + 'ms')
        root.setProperty('--app-header-height', height + 'px')
        root.setProperty('--app-header-offset', height - offset + 'px')

        if (header.value) header.value.style.transform = offset ? `translateY(${-offset}px)` : ''
    }

    function follow() {
        frame = null

        if (!source || isLocked.value) return

        const { y: scrolled, bottom, height } = position(source)
        const y = clamp(scrolled, 0, Math.max(bottom, 0))
        const last = seen.get(source) ?? { y: 0, height }
        const delta = last.height === height ? y - last.y : 0

        seen.set(source, { y, height })
        sourceY = y

        const next = Math.min(offset + delta, y)

        if (clamp(next, 0, headerHeight()) !== offset) move(next, false)

        scheduleSettle()
    }

    function settle() {
        settleTimer = null
        const height = headerHeight()

        if (touching || offset === 0 || offset === height) return

        move(offset > height / 2 && sourceY >= height ? height : 0, true)
    }

    function scheduleSettle() {
        clearTimeout(settleTimer)
        settleTimer = setTimeout(settle, SETTLE_DELAY)
    }

    function onScroll(event) {
        const scroller = event.target instanceof Element ? event.target : window

        if (scroller !== window && !isPage(scroller)) return

        source = scroller
        frame ??= requestAnimationFrame(follow)
    }

    function onTouchStart() {
        touching = true
    }

    function onTouchEnd(event) {
        touching = event.touches.length > 0

        if (!touching && source) scheduleSettle()
    }

    /**
     * Een nieuwe pagina staat bovenaan zonder dat er gescrold is. Kwam je van de
     * planner, dan is zijn vak weg en hoort de balk weer bij het venster.
     */
    function onNavigate() {
        if (!source?.isConnected) source = window

        frame ??= requestAnimationFrame(follow)
    }

    /** Wie met het toetsenbord in de balk belandt, moet hem ook zien. */
    function reveal() {
        move(0, true)
    }

    function onResize() {
        move(offset, false)
    }

    const listeners = [
        [document, 'scroll', onScroll],
        [document, 'touchstart', onTouchStart],
        [document, 'touchend', onTouchEnd],
        [document, 'touchcancel', onTouchEnd],
        [window, 'resize', onResize],
    ]

    onMounted(() => {
        move(0, false)
        activeReveal = reveal
        stopNavigate = router.on('navigate', onNavigate)

        for (const [target, type, listener] of listeners) {
            target.addEventListener(type, listener, { capture: true, passive: true })
        }
    })

    onBeforeUnmount(() => {
        stopNavigate()
        cancelAnimationFrame(frame)
        clearTimeout(settleTimer)

        if (activeReveal === reveal) activeReveal = null

        for (const [target, type, listener] of listeners) {
            target.removeEventListener(type, listener, { capture: true })
        }

        for (const property of ['--app-header-offset', '--app-header-height', '--app-header-transition']) {
            document.documentElement.style.removeProperty(property)
        }
    })

    return { reveal }
}
