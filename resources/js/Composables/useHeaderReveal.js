import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useScrollLock } from '@/Composables/useScrollLock.js'

/**
 * Zoveel moet er in één richting gescrold zijn voordat de balk reageert: een
 * duim die even trilt is geen besluit om te gaan lezen of terug te gaan.
 */
const THRESHOLD = 8

/**
 * Een lijst die minstens zo'n deel van het scherm hoog is, is de pagina zelf:
 * de planner scrolt zijn week in een eigen vak en niet in het venster. Een
 * keuzelijst of een vak in een venster scrolt ook, maar dat is geen lezen.
 */
const PAGE_SHARE = 0.5

/**
 * De balk bovenin op een telefoon: weg zodra je naar beneden scrolt, terug zodra
 * je omhoog scrolt. Lezen krijgt het hele scherm, en zoeken of de meldingen
 * liggen één veeg omhoog verderop. Dat geldt voor het venster en voor elk vak
 * dat de pagina zelf is, zodat geen pagina het hoeft te regelen.
 *
 * Bovenaan staat hij altijd, anders gaapt daar het gat waar hij stond. Wat onder
 * de balk tegen de bovenrand plakt leest uit --app-header-offset hoe hoog hij
 * echt is, en krijgt nul zolang hij weg is.
 *
 * Een vak dat meegroeit als de balk weggaat, schuift onderaan terug omdat het
 * langer wordt. Dat is geen omhoog scrollen: een vak dat sinds de vorige keer
 * van hoogte veranderde, telt die keer niet mee.
 *
 * Een geopende foto of het afrondvenster zet de pagina even vast en springt
 * daarbij naar boven en weer terug. Dat is geen scrollen en telt dus niet mee.
 *
 * @param {import('vue').Ref<HTMLElement|null>} header
 */
export function useHeaderReveal(header) {
    const hidden = ref(false)
    const { isLocked } = useScrollLock()

    const seen = new WeakMap()
    let source = null
    let frame = null

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

    function update() {
        frame = null
        const element = header.value
        const scroller = source

        if (!scroller || !element || element.getClientRects().length === 0 || isLocked.value) return

        const { y: scrolled, bottom, height } = position(scroller)
        const y = Math.min(Math.max(scrolled, 0), bottom)
        const last = seen.get(scroller)
        const settled = last?.height === height

        if (y <= element.offsetHeight) {
            hidden.value = false
        } else if (settled && Math.abs(y - last.y) < THRESHOLD) {
            return
        } else if (settled) {
            hidden.value = y > last.y
        }

        seen.set(scroller, { y, height })
    }

    function onScroll(event) {
        const scroller = event.target instanceof Element ? event.target : window

        if (scroller !== window && !isPage(scroller)) return

        source = scroller
        frame ??= requestAnimationFrame(update)
    }

    /** Wie met het toetsenbord in de balk belandt, moet hem ook zien. */
    function reveal() {
        hidden.value = false
    }

    function publishOffset() {
        const height = hidden.value ? 0 : (header.value?.offsetHeight ?? 0)

        document.documentElement.style.setProperty('--app-header-offset', height + 'px')
    }

    watch(hidden, publishOffset)

    onMounted(() => {
        publishOffset()
        document.addEventListener('scroll', onScroll, { capture: true, passive: true })
        window.addEventListener('resize', publishOffset)
    })

    onBeforeUnmount(() => {
        document.removeEventListener('scroll', onScroll, { capture: true })
        window.removeEventListener('resize', publishOffset)
        cancelAnimationFrame(frame)
        document.documentElement.style.removeProperty('--app-header-offset')
    })

    return { hidden, reveal }
}
